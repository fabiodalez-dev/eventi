<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\AccessibilityFeature;
use App\Enums\AttendanceMode;
use App\Enums\EventStatus;
use App\Enums\FollowableType;
use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Enums\TimeOfDay;
use App\Enums\VenueStatus;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Organizer;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
use App\Services\Account\ContentPreferences;
use App\Services\Geo\GeoQueryInterface;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\DB;

/**
 * Il motore temporale della piattaforma (§8). Sito, API, calendario, feed,
 * notifiche e futura applicazione mobile interrogano le occorrenze **solo** da
 * qui: se "oggi", "stasera", "in corso" o "inizia tra poco" vengono ricalcolati
 * altrove, in sei mesi esisteranno due definizioni divergenti.
 *
 * ```php
 * EventOccurrenceQuery::for($city)->tonight()->inCategories(['musica'])->get();
 * ```
 *
 * Tre invarianti governano la classe:
 *
 * - **"Adesso" è sempre `now($city->timezone)`**, mai l'ora del server (§8.1).
 * - **Le finestre di giornata si leggono da `business_date`**, la colonna
 *   calcolata da `EventOccurrenceObserver`: è ciò che rende venerdì sera un
 *   concerto che finisce sabato alle 3:00 (§8.2).
 * - **Le finestre istantanee** ("in corso", "inizia tra poco") confrontano
 *   `starts_at` ed `effective_ends_at` con l'istante UTC corrispondente.
 *
 * La base della query è già ristretta agli eventi pubblicati della città: una
 * bozza non deve poter comparire in nessuna finestra pubblica.
 */
final class EventOccurrenceQuery
{
    /**
     * Ora locale a partire dalla quale un'occorrenza appartiene a "stasera" (§8.4).
     */
    private const EVENING_HOUR = 17;

    /**
     * Alias della distanza in metri, disponibile sui risultati dopo `near()`.
     */
    public const DISTANCE_ALIAS = 'distance_m';

    /**
     * Alias del criterio "in evidenza adesso", usato da `orderByRelevance()`.
     */
    private const FEATURED_ALIAS = 'is_promoted';

    /** @var Builder<EventOccurrence> */
    private Builder $query;

    private readonly CarbonImmutable $now;

    private OccurrenceOrdering $ordering = OccurrenceOrdering::Chronological;

    /** @var array{lat: float, lng: float}|null */
    private ?array $origin = null;

    /**
     * @param  non-empty-list<EventStatus>  $statuses  stati dell'evento padre ammessi in tutte le finestre
     */
    private function __construct(private readonly City $city, private readonly array $statuses)
    {
        $this->now = CarbonImmutable::now($city->timezone);
        $this->query = $this->baseQuery();
    }

    /**
     * Il costruttore di tutte le finestre pubbliche: **solo eventi pubblicati**.
     * Una bozza, un evento rifiutato o uno archiviato non compaiono in nessun
     * elenco, in nessuna mappa e in nessun conteggio.
     */
    public static function for(City $city): self
    {
        return (new self($city, [EventStatus::Published]))->withContentPreferences();
    }

    private function withContentPreferences(): self
    {
        $preferences = app(ContentPreferences::class);
        $hidden = $preferences->hidden($preferences->discoveryUser());
        if ($hidden !== []) {
            $this->query->withGlobalScope('content_preferences', fn (Builder $query) => $query->whereNotIn('events.category_id', $hidden));
        }

        return $this;
    }

    /**
     * Come `for()`, ma comprende anche gli **archiviati** (§14.5).
     *
     * Archiviare toglie dalle liste, non dal sito: l'archivio della scheda
     * locale e le date passate della scheda evento sono le due sole viste che
     * guardano indietro, e §11.9 le vuole piene — sono quelle che i motori di
     * ricerca continuano a mostrare per anni. Chiamarla su una finestra futura
     * non è un errore ma non ha senso: un evento archiviato con date future
     * non esiste, perché è la loro assenza a farlo archiviare.
     */
    public static function archiveFor(City $city): self
    {
        return (new self($city, [EventStatus::Published, EventStatus::Archived]))->withContentPreferences();
    }

    /** Management-only calendar for a single event. The caller must authorize update before exposing results. */
    public static function managementFor(Event $event): self
    {
        return (new self($event->city, EventStatus::cases()))->forEvent($event);
    }

    /**
     * "Adesso" nella città, che è l'unico adesso che questo prodotto conosce
     * (§8.1). Esposto perché i pannelli di gestione lavorano anche su ciò che
     * non è pubblicato — e quindi non passa dalle finestre qui sotto — ma non
     * devono per questo derivare una seconda idea di che giorno sia.
     */
    public function now(): CarbonImmutable
    {
        return $this->now;
    }

    /**
     * La giornata evento corrente, nel formato in cui è scritta la colonna
     * `business_date`. È la sola forma in cui "oggi" può uscire da questa
     * classe verso una query scritta altrove.
     */
    public function currentBusinessDate(): string
    {
        return $this->now->format('Y-m-d');
    }

    // ---------------------------------------------------------------- finestre

    /**
     * Giornata evento corrente della città.
     */
    public function today(): self
    {
        return $this->onDate($this->now);
    }

    /**
     * Giornata evento corrente, dalle 17:00 locali in poi (§8.4).
     */
    public function tonight(): self
    {
        $this->today();

        [$expression, $bindings] = $this->localStartsAt();

        $this->query->whereRaw(
            sprintf('HOUR(%s) >= ?', $expression),
            [...$bindings, self::EVENING_HOUR],
        );

        return $this;
    }

    public function tomorrow(): self
    {
        return $this->onDate($this->now->addDay());
    }

    /**
     * Venerdì, sabato e domenica della settimana corrente, saltando i giorni
     * già passati: chi chiede "questo weekend" di sabato non vuole vedere gli
     * eventi di venerdì.
     */
    public function weekend(): self
    {
        $friday = $this->now->startOfWeek(CarbonImmutable::MONDAY)->addDays(4);

        $days = [];

        foreach ([0, 1, 2] as $offset) {
            $day = $friday->addDays($offset);

            if ($day->format('Y-m-d') >= $this->now->format('Y-m-d')) {
                $days[] = $day->format('Y-m-d');
            }
        }

        $this->query->whereIn('event_occurrences.business_date', $days);

        return $this;
    }

    /**
     * In corso adesso. Esclude le categorie che non lo sopportano — una mostra
     * aperta tutti i giorni sarebbe permanentemente "in corso" e seppellirebbe
     * i concerti sotto contenuto inerte — e gli eventi di intera giornata.
     */
    public function ongoing(): self
    {
        $now = $this->nowUtc();

        $this->query
            ->where('event_occurrences.starts_at', '<=', $now)
            ->where('event_occurrences.effective_ends_at', '>=', $now)
            ->where('event_occurrences.status', OccurrenceStatus::Scheduled->value)
            ->where('categories.supports_ongoing', true)
            ->where('event_occurrences.is_all_day', false);

        $this->ordering = OccurrenceOrdering::Live;

        return $this;
    }

    /**
     * Comincia entro `city.starting_soon_minutes` (180 di default).
     */
    public function startingSoon(): self
    {
        $now = $this->nowUtc();

        $this->query
            ->where('event_occurrences.starts_at', '>', $now)
            ->where('event_occurrences.starts_at', '<=', $this->now->addMinutes($this->city->starting_soon_minutes)->utc()->format('Y-m-d H:i:s'))
            ->where('event_occurrences.status', OccurrenceStatus::Scheduled->value);

        $this->ordering = OccurrenceOrdering::Live;

        return $this;
    }

    /**
     * Intervallo di giornate evento, estremi compresi.
     */
    public function between(CarbonImmutable|DateTimeInterface|string $from, CarbonImmutable|DateTimeInterface|string $to): self
    {
        $start = $this->toLocalDate($from)->format('Y-m-d');
        $end = $this->toLocalDate($to)->format('Y-m-d');

        $this->query->whereBetween('event_occurrences.business_date', $start <= $end ? [$start, $end] : [$end, $start]);

        return $this;
    }

    public function onDate(CarbonImmutable|DateTimeInterface|string $date): self
    {
        $this->query->where('event_occurrences.business_date', $this->toLocalDate($date)->format('Y-m-d'));

        return $this;
    }

    /**
     * Dalla giornata evento corrente in avanti.
     *
     * È la finestra predefinita di ogni lista pubblica: senza, `/eventi`
     * mostrerebbe anche l'archivio. Sta qui e non nei controller perché "da
     * oggi in poi" è pur sempre una definizione di oggi (§8.1).
     */
    public function upcoming(): self
    {
        $this->query->where('event_occurrences.business_date', '>=', $this->now->format('Y-m-d'));

        return $this;
    }

    /** Saved agenda: ongoing dates stay visible until their effective end. */
    public function ended(bool $past = true): self
    {
        $this->query->where('event_occurrences.effective_ends_at', $past ? '<=' : '>', $this->nowUtc());

        return $this;
    }

    /** Date promuovibili: non terminate, annullate, rinviate o sostituite. */
    public function promotable(): self
    {
        $this->query
            ->where('event_occurrences.effective_ends_at', '>', $this->nowUtc())
            ->whereIn('event_occurrences.status', [OccurrenceStatus::Scheduled->value, OccurrenceStatus::SoldOut->value]);

        return $this;
    }

    /**
     * Giornate evento già trascorse: l'archivio della scheda locale (§11.9).
     */
    public function past(): self
    {
        $this->query->where('event_occurrences.business_date', '<', $this->now->format('Y-m-d'));

        return $this;
    }

    /**
     * I prossimi `$days` giorni evento, oggi compreso: lo scroller della
     * homepage (§11.2) e il preset "questa settimana" di §13.2.
     */
    public function nextDays(int $days): self
    {
        return $this->between($this->now, $this->now->addDays(max($days, 1) - 1));
    }

    // ----------------------------------------------------------------- filtri

    /**
     * Entro un raggio in chilometri dal punto dato. Riguarda i soli eventi
     * ospitati da un locale: le coordinate stanno su `venues.location`.
     * Aggiunge alla select la distanza in metri (`distance_m`).
     */
    public function near(float $lat, float $lng, float $radiusKm): self
    {
        $geo = app(GeoQueryInterface::class);

        $this->query->whereNotNull('venues.id');

        $geo->withinRadius($this->query, $lat, $lng, $radiusKm, 'venues.location');
        $geo->distanceSelect($this->query, $lat, $lng, 'venues.location', self::DISTANCE_ALIAS);

        $this->origin = ['lat' => $lat, 'lng' => $lng];

        return $this;
    }

    /**
     * Dentro il rettangolo dato: è la query della mappa (§11.6), che chiede
     * ciò che sta nell'inquadratura e non ciò che sta entro un raggio.
     *
     * L'ordine dei parametri è quello di `bbox=minLng,minLat,maxLng,maxLat`,
     * lo stesso di §13.3, così che la stringa arrivata dal client si giri
     * nell'argomento senza rimescolarla per strada.
     *
     * Riguarda i soli eventi ospitati da un locale: le coordinate stanno su
     * `venues.location`, e un evento senza locale non ha un punto da disegnare.
     */
    public function withinBounds(float $minLng, float $minLat, float $maxLng, float $maxLat): self
    {
        $this->query->whereNotNull('venues.id');

        app(GeoQueryInterface::class)->withinBounds($this->query, $minLng, $minLat, $maxLng, $maxLat, 'venues.location');

        return $this;
    }

    /**
     * @param  array<int, Category|int|string>  $categories  modelli, id o slug
     */
    public function inCategories(array $categories): self
    {
        return $this->restrictByIdentifiers($categories, 'categories');
    }

    /**
     * @param  array<int, Tag|int|string>  $tags  modelli, id o slug
     */
    public function withTags(array $tags): self
    {
        [$ids, $slugs] = $this->splitIdentifiers($tags);

        if ($ids === [] && $slugs === []) {
            return $this;
        }

        $this->query->whereExists(function (QueryBuilder $sub) use ($ids, $slugs): void {
            $sub->from('event_tag')
                ->join('tags', 'tags.id', '=', 'event_tag.tag_id')
                ->whereColumn('event_tag.event_id', 'events.id')
                ->where(function (QueryBuilder $match) use ($ids, $slugs): void {
                    if ($ids !== []) {
                        $match->orWhereIn('tags.id', $ids);
                    }

                    if ($slugs !== []) {
                        $match->orWhereIn('tags.slug', $slugs);
                    }
                });
        });

        return $this;
    }

    public function priceFree(): self
    {
        $this->query->where('events.price_type', PriceType::Free->value);

        return $this;
    }

    /** Only dates that can still be proposed as a concrete outing. */
    public function availableForDiscovery(): self
    {
        $this->query->whereIn('event_occurrences.status', [OccurrenceStatus::Scheduled->value, OccurrenceStatus::Moved->value]);

        return $this;
    }

    /** Match the effective date price, not a cheaper default on the parent event. */
    public function discoveryBudget(int $amount): self
    {
        $overrideType = "JSON_UNQUOTE(JSON_EXTRACT(event_occurrences.price_override, '$.price_type'))";
        $type = "CASE WHEN {$overrideType} IN ('free','donation','ticket','membership','unknown') THEN {$overrideType} ELSE events.price_type END";
        $minimum = "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(event_occurrences.price_override, '$.price_min')), 'null'), events.price_min)";
        if ($amount === 0) {
            $this->query->whereRaw("({$type}) = ?", [PriceType::Free->value]);
        } else {
            $this->query->whereRaw("(({$type}) IN (?, ?) OR (({$type}) IN (?, ?) AND ({$minimum}) REGEXP '^[0-9]+([.][0-9]+)?$' AND CAST(({$minimum}) AS DECIMAL(12,2)) <= ?))", [PriceType::Free->value, PriceType::Donation->value, PriceType::Ticket->value, PriceType::Membership->value, $amount]);
        }

        return $this;
    }

    /**
     * A offerta libera: si entra senza biglietto ma si lascia qualcosa.
     */
    public function priceDonation(): self
    {
        $this->query->where('events.price_type', PriceType::Donation->value);

        return $this;
    }

    /**
     * Gratuiti, a offerta libera e a pagamento fino all'importo indicato.
     */
    public function priceMax(float|int $amount): self
    {
        $this->query->where(function (Builder $price) use ($amount): void {
            $price->whereIn('events.price_type', [PriceType::Free->value, PriceType::Donation->value])
                ->orWhere(function (Builder $paid) use ($amount): void {
                    $paid->whereNotNull('events.price_min')->where('events.price_min', '<=', $amount);
                });
        });

        return $this;
    }

    /**
     * A pagamento: biglietto o tessera (§13.2, `price=paid`). È l'insieme
     * complementare di gratuito e offerta libera, `unknown` escluso — un
     * prezzo che nessuno ha dichiarato non è un prezzo.
     */
    public function pricePaid(): self
    {
        $this->query->whereIn('events.price_type', [PriceType::Ticket->value, PriceType::Membership->value]);

        return $this;
    }

    /**
     * Modificate dopo un certo istante (§13.2, `updated_since`).
     *
     * Serve alla sincronizzazione offline dell'app: guarda `updated_at`
     * dell'occorrenza **e** dell'evento, perché correggere il titolo o il
     * prezzo cambia ciò che il client mostra su quella data senza toccare la
     * riga della data.
     */
    public function updatedSince(CarbonImmutable|DateTimeInterface|string $since): self
    {
        $instant = ($since instanceof DateTimeInterface
            ? CarbonImmutable::instance($since)
            : CarbonImmutable::parse($since, $this->city->timezone))
            ->utc()
            ->format('Y-m-d H:i:s');

        $this->query->where(function (Builder $changed) use ($instant): void {
            $changed->where('event_occurrences.updated_at', '>=', $instant)
                ->orWhere('events.updated_at', '>=', $instant);
        });

        return $this;
    }

    public function contentUpdatedSince(CarbonImmutable|DateTimeInterface|string $since): self
    {
        $instant = ($since instanceof DateTimeInterface
            ? CarbonImmutable::instance($since)
            : CarbonImmutable::parse($since, $this->city->timezone))
            ->utc()
            ->format('Y-m-d H:i:s');

        $this->query->where(function (Builder $changed) use ($instant): void {
            $changed->where('event_occurrences.updated_at', '>=', $instant)
                ->orWhere('events.updated_at', '>=', $instant)
                ->orWhere('venues.updated_at', '>=', $instant)
                ->orWhere('categories.updated_at', '>=', $instant)
                ->orWhereExists(function (QueryBuilder $media) use ($instant): void {
                    $media->from('media')
                        ->whereColumn('media.model_id', 'events.id')
                        ->where('media.model_type', Event::class)
                        ->where('media.updated_at', '>=', $instant);
                })
                ->orWhereExists(function (QueryBuilder $tags) use ($instant): void {
                    $tags->from('event_tag')
                        ->join('tags', 'tags.id', '=', 'event_tag.tag_id')
                        ->whereColumn('event_tag.event_id', 'events.id')
                        ->where('tags.updated_at', '>=', $instant);
                });
        });

        return $this;
    }

    /**
     * Fascia oraria locale dell'inizio: giorno, sera o notte.
     */
    public function timeOfDay(TimeOfDay|string $band): self
    {
        $band = $band instanceof TimeOfDay ? $band : TimeOfDay::from($band);

        [$expression, $bindings] = $this->localStartsAt();
        $hour = sprintf('HOUR(%s)', $expression);

        $this->query->whereRaw(
            $band->crossesMidnight()
                ? sprintf('(%s >= ? OR %s < ?)', $hour, $hour)
                : sprintf('(%s >= ? AND %s < ?)', $hour, $hour),
            [...$bindings, $band->startHour(), ...$bindings, $band->endHour()],
        );

        return $this;
    }

    public function atVenue(Venue|int $venue): self
    {
        $this->query->where('venues.id', $venue instanceof Venue ? $venue->getKey() : $venue);

        return $this;
    }

    public function byOrganizer(Organizer $organizer): self
    {
        $this->query->withoutGlobalScope('city');
        $this->query->where('events.organizer_id', $organizer->id);

        return $this;
    }

    /**
     * Come `atVenue()`, ma partendo dallo slug che sta nell'URL: risparmia una
     * interrogazione per risolvere il locale che è già in join.
     */
    public function atVenueSlug(string $slug): self
    {
        $this->query->where('venues.slug', $slug);

        return $this;
    }

    /**
     * Comune del locale che ospita l'evento: è il filtro "zona" di §11.3.
     */
    public function inMunicipality(string $municipality): self
    {
        $this->query->where('venues.municipality', $municipality);

        return $this;
    }

    /**
     * All'aperto (§11.3). È un attributo dell'evento, non del locale: lo stesso
     * circolo fa concerti in sala d'inverno e in cortile d'estate.
     */
    public function outdoor(): self
    {
        $this->query->where('events.is_outdoor', true);

        return $this;
    }

    /**
     * Il quartiere del locale: è il filtro «zona» a cui §11.3 punta davvero
     * dentro una città. `inMunicipality()` resta per la provincia — a Padova
     * il comune è lo stesso per tutti i locali e non separa niente, mentre
     * «Portello» e «Arcella» sì.
     */
    public function inZone(string $zone): self
    {
        $this->query->where('venues.zone', $zone);

        return $this;
    }

    /**
     * Ospitato da un locale in cui si entra senza scalini (§11.3).
     *
     * È la voce che il filtro «accessibile» ha sempre significato, e da
     * quando `venues.accessibility` è strutturato (`AccessibilityFeature`) ha
     * un nome invece di essere una chiave inventata dentro un JSON libero.
     */
    public function accessible(): self
    {
        return $this->hasAccessibilityFeature(AccessibilityFeature::StepFreeEntrance);
    }

    /**
     * Ospitato da un locale che dichiara **presente** una voce di
     * accessibilità.
     *
     * `true` e nient'altro: una voce dichiarata assente non entra, e una non
     * dichiarata nemmeno. Chi ha bisogno di un servizio igienico accessibile
     * non può ricevere in risposta un locale su cui non si sa.
     */
    public function hasAccessibilityFeature(AccessibilityFeature $feature): self
    {
        $this->query->where('venues.accessibility->'.$feature->value, true);

        return $this;
    }

    /**
     * Solo gli eventi messi in evidenza dalla redazione e non ancora scaduti
     * (§11.2, sezione "In evidenza").
     *
     * `featured_until` nullo significa "senza scadenza": è una scelta della
     * redazione, non un dato mancante.
     */
    public function featured(): self
    {
        $this->query
            ->where('events.is_featured', true)
            ->where(function (Builder $window): void {
                $window->whereNull('events.featured_until')
                    ->orWhere('events.featured_until', '>=', $this->nowUtc());
            });

        return $this;
    }

    /**
     * Le sole date di un evento. È così che la scheda evento elenca **tutte**
     * le date future (§11.5) senza rifare per conto proprio il conto di quali
     * siano future e di quali eventi siano pubblicati.
     */
    public function forEvent(Event|int $event): self
    {
        // A directly opened event (including a booked ticket) remains accessible.
        $this->query->withoutGlobalScope('content_preferences');
        $this->query->where('events.id', $event instanceof Event ? $event->getKey() : $event);

        return $this;
    }

    /**
     * Le date di un insieme di eventi. È il ponte fra la ricerca testuale —
     * che sa quali **eventi** somigliano a ciò che è stato scritto — e il
     * motore temporale, che è l'unico a sapere quali date siano ancora future
     * (§8.1). Un insieme vuoto non restituisce nulla, che è la risposta giusta
     * a una ricerca senza corrispondenze.
     *
     * @param  array<int, int>  $ids
     */
    public function forEvents(array $ids): self
    {
        $this->query->whereIn('events.id', $ids);

        return $this;
    }

    /**
     * Una singola occorrenza, presa **passando dal motore**: la base della
     * query è già ristretta agli eventi pubblicati e non cestinati della città,
     * quindi chiedere qui è anche il modo di verificare che quella data sia
     * davvero visibile al pubblico.
     */
    public function forOccurrence(EventOccurrence|int $occurrence): self
    {
        $this->query->withoutGlobalScope('content_preferences');
        $this->query->where(
            'event_occurrences.id',
            $occurrence instanceof EventOccurrence ? $occurrence->getKey() : $occurrence,
        );

        return $this;
    }

    /**
     * Le sole date che questa persona ha salvato (§15.3).
     *
     * È un filtro, non una finestra: quali di quelle date siano ancora future
     * lo decide `upcoming()`, come per tutto il resto. Sito e API chiedono
     * quindi `savedBy($user)->upcoming()`, e "da oggi in poi" resta una
     * definizione sola (§8.1).
     */
    public function savedBy(User $user): self
    {
        $this->query->whereExists(function (QueryBuilder $sub) use ($user): void {
            $sub->from('saved_events')
                ->whereColumn('saved_events.occurrence_id', 'event_occurrences.id')
                ->where('saved_events.user_id', $user->getKey());
        });

        return $this;
    }

    /**
     * Le date di ciò che questa persona segue: locali, tag e categorie
     * (§15.7). È il feed personalizzato, e come ogni altra lista prende la
     * finestra temporale da chi lo chiama.
     *
     * Chi non segue nulla non riceve tutto il catalogo ma niente: un feed di
     * ciò che si segue, quando non si segue niente, è vuoto — e la pagina
     * risponde con l'avvio guidato di §15.7, che è un'altra cosa e la decide
     * il controller.
     */
    public function followedBy(User $user, bool $includeContentPreferences = false, bool $notifyingOnly = false): self
    {
        $venues = $user->followedIds(FollowableType::Venue, $notifyingOnly);
        $organizers = Organizer::query()->where('is_active', true)
            ->whereIn('id', $user->followedIds(FollowableType::Organizer, $notifyingOnly))->pluck('id')->all();
        $categories = $user->followedIds(FollowableType::Category, $notifyingOnly);
        if ($includeContentPreferences) {
            $categories = array_values(array_unique([...$categories, ...app(ContentPreferences::class)->selection($user)['categories']]));
        }
        $tags = $user->followedIds(FollowableType::Tag, $notifyingOnly);

        if ($venues === [] && $organizers === [] && $categories === [] && $tags === []) {
            $this->query->whereRaw('1 = 0');

            return $this;
        }

        $this->query->where(function (Builder $match) use ($venues, $organizers, $categories, $tags): void {
            if ($organizers !== []) {
                $match->orWhereIn('events.organizer_id', $organizers);
            }
            if ($venues !== []) {
                $match->orWhereIn('venues.id', $venues);
            }

            if ($categories !== []) {
                $match->orWhereIn('events.category_id', $categories);
            }

            if ($tags !== []) {
                $match->orWhereExists(function (QueryBuilder $sub) use ($tags): void {
                    $sub->from('event_tag')
                        ->whereColumn('event_tag.event_id', 'events.id')
                        ->whereIn('event_tag.tag_id', $tags);
                });
            }
        });

        return $this;
    }

    /**
     * Un insieme di date, prese passando dal motore. È il modo in cui la
     * migrazione dei salvataggi di un anonimo (§15.1) scarta in **una** query
     * ciò che non esiste, ciò che non è pubblico e — con `upcoming()` — ciò
     * che è già passato: tre regole che nessuno deve riscrivere altrove.
     *
     * @param  array<int, int>  $ids
     */
    public function forOccurrences(array $ids): self
    {
        $this->query->whereIn('event_occurrences.id', $ids);

        return $this;
    }

    /**
     * Esclude un evento dai risultati: serve alle sezioni "eventi simili" e
     * "altri eventi in questo locale", che non devono riproporre la scheda su
     * cui si trova già chi legge.
     */
    public function excludingEvent(Event|int $event): self
    {
        $this->query->where('events.id', '!=', $event instanceof Event ? $event->getKey() : $event);

        return $this;
    }

    /**
     * Ricerca parziale su contenuti pubblici, locale e tag approvati.
     *
     * @param  list<int>  $matchingEventIds  corrispondenze aggiuntive da Scout
     */
    public function search(string $term, array $matchingEventIds = []): self
    {
        $term = trim($term);

        if ($term === '') {
            return $this;
        }

        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        $this->query->where(function (Builder $match) use ($pattern, $matchingEventIds): void {
            $match->where('events.title', 'like', $pattern)
                ->orWhere('events.subtitle', 'like', $pattern)
                ->orWhere('events.short_description', 'like', $pattern)
                ->orWhere('events.organizer_name', 'like', $pattern)
                ->orWhereExists(fn ($organizers) => $organizers->selectRaw('1')->from('organizers')
                    ->whereColumn('organizers.id', 'events.organizer_id')->where('organizers.is_active', true)
                    ->where(fn ($text) => $text->where('organizers.name', 'like', $pattern)->orWhere('organizers.description', 'like', $pattern)))
                ->orWhere('events.description', 'like', $pattern)
                ->orWhere('venues.name', 'like', $pattern)
                ->orWhere('venues.short_description', 'like', $pattern)
                ->orWhere('venues.description', 'like', $pattern)
                ->orWhereIn('events.id', $matchingEventIds)
                ->orWhereExists(function ($tags) use ($pattern): void {
                    $tags->selectRaw('1')->from('event_tag')
                        ->join('tags', 'tags.id', '=', 'event_tag.tag_id')
                        ->whereColumn('event_tag.event_id', 'events.id')
                        ->where('tags.is_approved', true)
                        ->where('tags.name', 'like', $pattern);
                });
        });

        return $this;
    }

    /**
     * Una sola data per evento, con limite applicato dal database, non dopo
     * aver caricato tutte le ricorrenze del catalogo.
     *
     * @return Collection<int, EventOccurrence>
     */
    public function firstPerEvent(int $limit): Collection
    {
        $ranked = (clone $this->query)->reorder()
            ->select(['event_occurrences.id', 'event_occurrences.starts_at'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY event_occurrences.event_id ORDER BY event_occurrences.starts_at, event_occurrences.id) as search_rank')
            ->toBase();
        $ids = DB::query()->fromSub($ranked, 'search_dates')
            ->where('search_rank', 1)->orderBy('starts_at')->orderBy('id')
            ->limit(max(1, $limit))->pluck('id');

        return (clone $this->query)->whereIn('event_occurrences.id', $ids)
            ->reorder()->orderBy('event_occurrences.starts_at')->orderBy('event_occurrences.id')->get();
    }

    // ------------------------------------------------------------ ordinamento

    /**
     * In evidenza e punteggio redazionale prima della cronologia.
     */
    public function orderByRelevance(): self
    {
        $this->ordering = OccurrenceOrdering::Relevance;

        return $this;
    }

    /**
     * Distanza crescente (§13.2, `sort=distance`). Senza una `near()` che
     * abbia fissato l'origine non c'è distanza da ordinare e la lista resta
     * cronologica: chiedere l'ordine per distanza senza dare la posizione non
     * è un errore, è una richiesta che non si può esaudire.
     */
    public function orderByDistance(): self
    {
        $this->ordering = OccurrenceOrdering::Distance;

        return $this;
    }

    /**
     * Più salvati e più visti prima (§13.2, `sort=popular`).
     *
     * La popolarità è dell'**evento**, non della singola data: `saves_count` e
     * `views_count` stanno su `events`. A parità di numeri torna la
     * cronologia, altrimenti due eventi mai salvati uscirebbero in ordine
     * arbitrario.
     */
    public function orderByPopularity(): self
    {
        $this->ordering = OccurrenceOrdering::Popular;

        return $this;
    }

    /**
     * Dalla data più recente alla più vecchia: è l'ordine con cui si legge un
     * archivio (§11.9), dove l'ultima serata viene prima di quella di un anno fa.
     */
    public function orderByNewestFirst(): self
    {
        $this->ordering = OccurrenceOrdering::ReverseChronological;

        return $this;
    }

    // ------------------------------------------------------------- esecuzione

    /**
     * @return Collection<int, EventOccurrence>
     */
    public function get(): Collection
    {
        return $this->build()->get();
    }

    /**
     * `$pageName` esiste perché una pagina può ospitare due elenchi paginati:
     * la scheda di un locale ha i prossimi eventi e l'archivio, e devono poter
     * essere sfogliati uno senza trascinarsi l'altro (§11.9).
     *
     * @return LengthAwarePaginator<int, EventOccurrence>
     */
    public function paginate(int $perPage = 24, ?int $page = null, string $pageName = 'page'): LengthAwarePaginator
    {
        return $this->build()->paginate(perPage: $perPage, pageName: $pageName, page: $page);
    }

    /**
     * @return CursorPaginator<int, EventOccurrence>
     */
    public function cursorPaginate(int $perPage = 24, ?string $cursor = null): CursorPaginator
    {
        return $this->build()->cursorPaginate(perPage: $perPage, cursor: $cursor);
    }

    /** @return list<int> */
    public function eventIds(): array
    {
        return (clone $this->query)->reorder()->distinct()->pluck('event_occurrences.event_id')->all();
    }

    public function count(): int
    {
        return $this->query->count('event_occurrences.id');
    }

    /**
     * Quante occorrenze cadono in ciascuna giornata evento, nella forma
     * `['2026-09-05' => 7]`. È una sola query aggregata: lo scroller dei
     * prossimi giorni (§11.2) e i conteggi del calendario (§11.8) non devono
     * caricare centinaia di modelli per contarli.
     *
     * @return array<string, int>
     */
    public function countsByBusinessDate(): array
    {
        /** @var array<string, int> $counts */
        $counts = $this->groupedCounts('event_occurrences.business_date')
            ->mapWithKeys(static fn (int|string $count, int|string $date): array => [
                CarbonImmutable::parse((string) $date)->format('Y-m-d') => (int) $count,
            ])
            ->all();

        return $counts;
    }

    /**
     * Quante occorrenze ha ciascun locale, nella forma `[12 => 4]`. Serve alla
     * lista dei locali, che mostra il numero di date in programma.
     *
     * @return array<int, int>
     */
    public function countsByVenue(): array
    {
        return $this->keyedCounts('venues.id');
    }

    /**
     * Quante occorrenze ha ciascuna categoria. È ciò che permette alla griglia
     * per categoria della homepage di non disegnare le caselle che porterebbero
     * a una lista vuota (§8.6).
     *
     * @return array<int, int>
     */
    public function countsByCategory(): array
    {
        return $this->keyedCounts('events.category_id');
    }

    /**
     * I punti di `GET /v1/map/occurrences` (§13.3): **una data per marcatore**,
     * con il carico minimo che quell'endpoint prescrive.
     *
     * La mappa del sito ragiona per locale (`venueMarkers()`, D26) perché due
     * concerti nello stesso circolo hanno le stesse coordinate e resterebbero
     * sovrapposti; l'API invece consegna le date, e come raggrupparle lo
     * decide il client — che su un telefono ha regole proprie.
     *
     * Le righe non diventano modelli: sette campi per marcatore, moltiplicati
     * per centinaia di marcatori, non valgono l'idratazione di altrettanti
     * oggetti Eloquent con le loro relazioni. L'ordine è quello cronologico e
     * non quello scelto da chi chiama: la lista qui è un insieme di punti da
     * disegnare tutti insieme, e un ordinamento per rilevanza porterebbe nella
     * `SELECT` alias che questa proiezione ridotta non contiene.
     *
     * @return list<array{id: int, event_id: int, lat: float, lng: float, category_id: int, title: string, starts_at: string}>
     */
    public function occurrencePoints(int $limit): array
    {
        $rows = (clone $this->query)
            ->reorder()
            ->whereNotNull('venues.id')
            ->orderBy('event_occurrences.starts_at')
            ->orderBy('event_occurrences.id')
            ->select([
                'event_occurrences.id as point_id',
                'event_occurrences.event_id as point_event_id',
                'event_occurrences.starts_at as point_starts_at',
                'venues.lat as point_lat',
                'venues.lng as point_lng',
                'events.category_id as point_category_id',
                'events.title as point_title',
            ])
            ->limit(max($limit, 1))
            ->toBase()
            ->get();

        $points = [];

        foreach ($rows as $row) {
            $values = (array) $row;

            $points[] = [
                'id' => (int) ($values['point_id'] ?? 0),
                'event_id' => (int) ($values['point_event_id'] ?? 0),
                'lat' => (float) ($values['point_lat'] ?? 0),
                'lng' => (float) ($values['point_lng'] ?? 0),
                'category_id' => (int) ($values['point_category_id'] ?? 0),
                'title' => (string) ($values['point_title'] ?? ''),
                'starts_at' => (string) ($values['point_starts_at'] ?? ''),
            ];
        }

        return $points;
    }

    /**
     * I punti che la mappa disegna: **un marcatore per locale**, non uno per
     * data (§11.6).
     *
     * Due eventi nello stesso locale hanno le stesse identiche coordinate:
     * disegnati come due marcatori resterebbero sovrapposti per sempre, perché
     * nessuno zoom li separa e il raggruppamento non li scioglie. Un marcatore
     * per locale, con il numero di date che vi cadono dentro, è la sola forma
     * che si possa davvero toccare.
     *
     * Una sola interrogazione, con due funzioni di finestra: il conteggio per
     * locale e la posizione della data più vicina, che è quella da cui si
     * prende la categoria — e quindi il colore. La sottoquery è necessaria
     * perché una funzione di finestra non si può filtrare nel `WHERE` che la
     * calcola.
     *
     * @return list<array{venue_id: int, venue_name: string, lat: float, lng: float, category_id: int, occurrence_id: int, count: int}>
     */
    public function venueMarkers(int $limit): array
    {
        $inner = (clone $this->query)
            ->where(fn ($query) => $query->whereNull('events.content_details->attendance_mode')->orWhere('events.content_details->attendance_mode', '!=', AttendanceMode::Online->value))
            ->reorder()
            ->whereNotNull('venues.id')
            ->select([
                'venues.id as marker_venue_id',
                /* Il nome serve a ETICHETTARE il marcatore: e' un elemento con
                   `role="button"`, e senza nome si annuncia col proprio numero
                   di riga a chi naviga con uno screen reader. */
                'venues.name as marker_venue_name',
                'venues.lat as marker_lat',
                'venues.lng as marker_lng',
                'events.category_id as marker_category_id',
                'event_occurrences.id as marker_occurrence_id',
            ])
            ->selectRaw('COUNT(*) OVER (PARTITION BY venues.id) as marker_count')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY venues.id ORDER BY event_occurrences.starts_at, event_occurrences.id) as marker_rank')
            ->toBase();

        $rows = DB::query()
            ->fromSub($inner, 'markers')
            ->where('marker_rank', 1)
            ->orderByDesc('marker_count')
            ->orderBy('marker_venue_id')
            ->limit(max($limit, 1))
            ->get();

        $markers = [];

        foreach ($rows as $row) {
            $values = (array) $row;

            $markers[] = [
                'venue_id' => (int) ($values['marker_venue_id'] ?? 0),
                'venue_name' => (string) ($values['marker_venue_name'] ?? ''),
                'lat' => (float) ($values['marker_lat'] ?? 0),
                'lng' => (float) ($values['marker_lng'] ?? 0),
                'category_id' => (int) ($values['marker_category_id'] ?? 0),
                'occurrence_id' => (int) ($values['marker_occurrence_id'] ?? 0),
                'count' => (int) ($values['marker_count'] ?? 0),
            ];
        }

        return $markers;
    }

    /**
     * Conteggio per giornata evento **e** i primi titoli di ciascuna, in una
     * sola interrogazione: è ciò che disegna il calendario mensile (§11.8), che
     * mostra quanti eventi cadono in un giorno e due o tre titoli d'assaggio.
     *
     * Il vincolo di §11.8 — una sola query aggregata per mese — si rispetta
     * leggendo le righe grezze una volta e raggruppandole qui: due query
     * separate, una per contare e una per i titoli, sarebbero due letture della
     * stessa tabella per rispondere alla stessa domanda.
     *
     * Le righe non diventano modelli: al calendario servono una data e una
     * stringa, e idratare trecento occorrenze per stamparne i titoli sarebbe
     * lavoro buttato.
     *
     * @return array<string, array{count: int, titles: list<string>}>
     */
    public function dailyDigest(int $titlesPerDay = 3): array
    {
        $rows = (clone $this->query)
            ->reorder()
            ->select(['event_occurrences.business_date as digest_date', 'events.title as digest_title'])
            ->orderBy('event_occurrences.business_date')
            ->orderBy('event_occurrences.starts_at')
            ->orderBy('event_occurrences.id')
            ->toBase()
            ->get();

        /** @var array<string, array{count: int, titles: list<string>}> $digest */
        $digest = [];

        foreach ($rows as $row) {
            $values = (array) $row;
            $date = CarbonImmutable::parse((string) ($values['digest_date'] ?? ''))->format('Y-m-d');

            $digest[$date] ??= ['count' => 0, 'titles' => []];
            $digest[$date]['count']++;

            if (count($digest[$date]['titles']) < max($titlesPerDay, 0)) {
                $digest[$date]['titles'][] = (string) ($values['digest_title'] ?? '');
            }
        }

        return $digest;
    }

    // ----------------------------------------------------------------- interno

    /**
     * @return Builder<EventOccurrence>
     */
    private function baseQuery(): Builder
    {
        return EventOccurrence::query()
            ->select('event_occurrences.*')
            ->join('events', 'events.id', '=', 'event_occurrences.event_id')
            ->join('categories', 'categories.id', '=', 'events.category_id')
            ->leftJoin('venues', function (JoinClause $join): void {
                $join->on('venues.id', '=', DB::raw('COALESCE(event_occurrences.venue_id, events.venue_id)'))->whereNull('venues.deleted_at');
            })
            ->withGlobalScope('city', fn (Builder $query) => $query->where('events.city_id', $this->city->getKey()))
            ->whereIn('events.status', array_map(static fn (EventStatus $status): string => $status->value, $this->statuses))
            ->whereNull('events.deleted_at')
            /*
             * Un locale sospeso o rifiutato si porta via i propri eventi.
             *
             * Senza questa clausola la sospensione era un'etichetta interna:
             * la scheda del locale spariva dall'elenco, ma i suoi eventi
             * restavano in home, in mappa e nei feed. Chi premeva «Sospendi»
             * credeva di aver tolto qualcosa dal sito e non toglieva nulla —
             * il modo peggiore di fallire, perche' nessuno va a controllare.
             *
             * Il `whereNull` non e' ridondante: la giunzione e' esterna perche'
             * un evento puo' non avere locale (una piazza, una manifestazione
             * diffusa), e quelli devono restare visibili.
             */
            ->where(function (Builder $pubblico): void {
                $pubblico
                    ->whereRaw('COALESCE(event_occurrences.venue_id, events.venue_id) IS NULL')
                    ->orWhereIn('venues.status', VenueStatus::valoriSenzaProvvedimento());
            });
    }

    /**
     * L'ordinamento si applica qui e non nei filtri: `get()` chiamato due volte
     * non deve accumulare due volte le stesse clausole.
     *
     * @return Builder<EventOccurrence>
     */
    private function build(): Builder
    {
        $query = clone $this->query;

        match ($this->ordering) {
            OccurrenceOrdering::Live => $this->applyLiveOrdering($query),
            OccurrenceOrdering::Relevance => $this->applyRelevanceOrdering($query),
            OccurrenceOrdering::Distance => $this->applyDistanceFirstOrdering($query),
            OccurrenceOrdering::ReverseChronological => $this->applyReverseChronologicalOrdering($query),
            OccurrenceOrdering::Popular => $this->applyPopularityOrdering($query),
            OccurrenceOrdering::Chronological => $this->applyChronologicalOrdering($query),
        };

        return $query;
    }

    /**
     * §8.5, nell'ordine: già in corso prima di chi deve cominciare, minuti
     * mancanti all'inizio crescenti, distanza, punteggio redazionale, id.
     * L'ultimo criterio non è decorativo: senza, due righe identiche
     * uscirebbero in ordine casuale e i test non sarebbero riproducibili.
     *
     * Il primo criterio non ha bisogno di una clausola propria: chi è già in
     * corso ha `starts_at <= adesso` e chi deve cominciare `starts_at > adesso`,
     * quindi l'ordine crescente di `starts_at` — che è il secondo criterio —
     * mette per costruzione i primi davanti ai secondi. Una `CASE` aggiuntiva
     * non cambierebbe una riga di risultato e renderebbe l'ordinamento non
     * esprimibile da `cursorPaginate()`, che sa costruire il proprio cursore
     * solo su colonne, non su espressioni grezze.
     *
     * @param  Builder<EventOccurrence>  $query
     */
    private function applyLiveOrdering(Builder $query): void
    {
        $query->orderBy('event_occurrences.starts_at');

        $this->applyDistanceOrdering($query);

        $query->addSelect('events.editorial_score')
            ->orderByDesc('events.editorial_score')
            ->orderBy('event_occurrences.id');
    }

    /**
     * In evidenza (e non scaduto) prima di tutto, poi punteggio redazionale,
     * cronologia e id.
     *
     * "In evidenza adesso" non è una colonna ma una condizione su due campi:
     * entra nella select come alias e da lì ordina. La forma è scelta perché
     * `cursorPaginate()` sappia risalire dall'alias all'espressione originale
     * e costruire il cursore: con un `orderByRaw` non può, e la seconda pagina
     * di ogni lista ordinata così finisce in errore.
     *
     * L'istante è scritto nell'espressione invece di essere legato a un
     * parametro perché un segnaposto dentro l'alias verrebbe copiato nel
     * `where` del cursore senza il proprio binding, disallineando tutti gli
     * altri. Non è un dato d'ingresso: è `$this->now` formattato.
     *
     * @param  Builder<EventOccurrence>  $query
     */
    private function applyRelevanceOrdering(Builder $query): void
    {
        $query->selectRaw(sprintf(
            "(CASE WHEN events.is_featured = 1 AND (events.featured_until IS NULL OR events.featured_until >= '%s') THEN 0 ELSE 1 END) as %s",
            $this->nowUtc(),
            self::FEATURED_ALIAS,
        ))
            ->addSelect('events.editorial_score')
            ->orderBy(self::FEATURED_ALIAS)
            ->orderByDesc('events.editorial_score')
            ->orderBy('event_occurrences.starts_at')
            ->orderBy('event_occurrences.id');
    }

    /**
     * @param  Builder<EventOccurrence>  $query
     */
    private function applyChronologicalOrdering(Builder $query): void
    {
        $query->orderBy('event_occurrences.starts_at')
            ->orderBy('event_occurrences.id');
    }

    /**
     * @param  Builder<EventOccurrence>  $query
     */
    private function applyPopularityOrdering(Builder $query): void
    {
        $query->addSelect(['events.saves_count', 'events.views_count'])
            ->orderByDesc('events.saves_count')
            ->orderByDesc('events.views_count');

        $this->applyChronologicalOrdering($query);
    }

    /**
     * @param  Builder<EventOccurrence>  $query
     */
    private function applyReverseChronologicalOrdering(Builder $query): void
    {
        $query->orderByDesc('event_occurrences.starts_at')
            ->orderByDesc('event_occurrences.id');
    }

    /**
     * Distanza prima di tutto, poi cronologia: senza posizione non c'è alcun
     * alias da ordinare e resta la sola cronologia.
     *
     * @param  Builder<EventOccurrence>  $query
     */
    private function applyDistanceFirstOrdering(Builder $query): void
    {
        $this->applyDistanceOrdering($query);
        $this->applyChronologicalOrdering($query);
    }

    /**
     * La distanza ordina le sole sezioni dal vivo, ed è il terzo criterio di
     * §8.5: nelle liste cronologiche resta un dato esposto (`distance_m`), non
     * un ordinamento — chi cerca "oggi" vuole l'ordine del tempo.
     *
     * @param  Builder<EventOccurrence>  $query
     */
    private function applyDistanceOrdering(Builder $query): void
    {
        if ($this->origin !== null) {
            $query->orderBy(self::DISTANCE_ALIAS);
        }
    }

    /**
     * @return array<int, int>
     */
    private function keyedCounts(string $column): array
    {
        /** @var array<int, int> $counts */
        $counts = $this->groupedCounts($column)
            ->mapWithKeys(static fn (int|string $count, int|string $key): array => [(int) $key => (int) $count])
            ->all();

        return $counts;
    }

    /**
     * Un solo `GROUP BY` sulla query già filtrata. Le colonne raggruppate sono
     * costanti scritte nel codice, mai valori in arrivo dalla richiesta.
     *
     * @return BaseCollection<array-key, int|string>
     */
    private function groupedCounts(string $column): BaseCollection
    {
        return (clone $this->query)
            ->reorder()
            ->whereNotNull($column)
            ->select($column)
            ->selectRaw('COUNT(*) as occurrences_count')
            ->groupBy($column)
            ->pluck('occurrences_count', $column);
    }

    /**
     * Espressione SQL che riporta `starts_at` (UTC) all'ora locale della città.
     *
     * `CONVERT_TZ` con nome di fuso richiede le tabelle dei fusi caricate nel
     * server, che sulla shared hosting non sono garantite; uno scostamento
     * fisso sbaglierebbe di un'ora per metà anno. Si genera quindi un `CASE`
     * dai cambi di ora reali del fuso, presi dal database dei fusi di PHP.
     *
     * @return array{0: string, 1: array<int, int|string>}
     */
    private function localStartsAt(): array
    {
        $column = 'event_occurrences.starts_at';
        $transitions = (new DateTimeZone($this->city->timezone))->getTransitions(
            $this->now->subYears(2)->getTimestamp(),
            $this->now->addYears(5)->getTimestamp(),
        );

        if ($transitions === []) {
            return [$column, []];
        }

        $sql = 'CASE';
        $bindings = [];

        foreach (array_slice($transitions, 1) as $index => $transition) {
            $sql .= sprintf(' WHEN %s < ? THEN DATE_ADD(%s, INTERVAL ? SECOND)', $column, $column);
            $bindings[] = CarbonImmutable::createFromTimestamp($transition['ts'], 'UTC')->format('Y-m-d H:i:s');
            $bindings[] = (int) $transitions[$index]['offset'];
        }

        $sql .= sprintf(' ELSE DATE_ADD(%s, INTERVAL ? SECOND) END', $column);
        $bindings[] = (int) $transitions[count($transitions) - 1]['offset'];

        return [$sql, $bindings];
    }

    /**
     * Restringe la query a una lista di id o slug su una tabella già in join.
     *
     * @param  array<int, Category|Tag|int|string>  $values
     */
    private function restrictByIdentifiers(array $values, string $table): self
    {
        [$ids, $slugs] = $this->splitIdentifiers($values);

        if ($ids === [] && $slugs === []) {
            return $this;
        }

        $this->query->where(function (Builder $match) use ($ids, $slugs, $table): void {
            if ($ids !== []) {
                $match->orWhereIn($table.'.id', $ids);
            }

            if ($slugs !== []) {
                $match->orWhereIn($table.'.slug', $slugs);
            }
        });

        return $this;
    }

    /**
     * @param  array<int, Category|Tag|int|string>  $values
     * @return array{0: array<int, int>, 1: array<int, string>}
     */
    private function splitIdentifiers(array $values): array
    {
        $ids = [];
        $slugs = [];

        foreach ($values as $value) {
            if ($value instanceof Category || $value instanceof Tag) {
                $ids[] = (int) $value->getKey();

                continue;
            }

            if (is_int($value) || ctype_digit($value)) {
                $ids[] = (int) $value;

                continue;
            }

            $slugs[] = $value;
        }

        return [$ids, $slugs];
    }

    private function toLocalDate(CarbonImmutable|DateTimeInterface|string $value): CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->setTimezone($this->city->timezone);
        }

        return CarbonImmutable::parse($value, $this->city->timezone);
    }

    private function nowUtc(): string
    {
        return $this->now->utc()->format('Y-m-d H:i:s');
    }
}
