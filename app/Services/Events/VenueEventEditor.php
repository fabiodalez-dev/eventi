<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Enums\EventSource;
use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\TicketTier;
use App\Models\User;
use App\Models\Venue;
use App\Support\BeforeGoingDefaults;
use App\Support\EditorContent;
use App\Support\VenueEventDefaults;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Il salvataggio di un evento del locale, scritto una volta per il pannello
 * (`/gestione`, pagine «Nuovo evento» e «Modifica evento») e per l'app.
 *
 * Le regole stavano dentro le due pagine Filament; qui restano identiche:
 *
 * - **la bozza** si salva con il solo titolo, e la categoria mancante prende
 *   quella abituale del locale (o la prima del catalogo): senza un ripiego
 *   `events.category_id`, obbligatoria nello schema, non la lascerebbe
 *   salvare;
 * - **la prima data** si scrive con la creazione; giorno operativo e fine
 *   effettiva li calcola l'observer;
 * - **i valori imparati**: categoria, tipo di prezzo, prezzo minimo e ora
 *   abituale diventano i predefiniti del prossimo evento (§10.2);
 * - **il ritorno in revisione**: su un evento pubblicato di un locale che non
 *   pubblica da sé, cambiare testo, link, locandina o voci del listino lo
 *   rimanda alla redazione; prezzo e categoria no (`EventReviewRule`).
 */
final class VenueEventEditor
{
    /** Oltre una dozzina di settori non è più un listino ma una mappa della sala (`TicketTiersField::MAX_TIERS`). */
    public const int MAX_TIERS = 12;

    /**
     * Le colonne dell'evento che la creazione scrive. Tutto il resto —
     * verifica, evidenza, punteggio, provenienza — è della redazione.
     */
    public const CREATE_COLUMNS = [
        'custom_location', 'title', 'description', 'category_id', 'price_type', 'price_min', 'price_max',
        'ticket_url', 'booking_url', 'external_links', 'content_details',
    ];

    /** Le colonne che la modifica scrive: solo quelle offerte dal modulo. */
    public const EDIT_COLUMNS = [
        'title', 'description', 'category_id', 'price_type', 'price_min', 'price_max',
        'ticket_url', 'booking_url', 'external_links', 'facts', 'content_details',
        'seo', 'organizer_name', 'organizer_url', 'custom_location',
    ];

    /** I campi che, cambiati su un evento pubblicato, chiedono una nuova revisione. */
    public const CONTENT_FIELDS = [
        'title', 'description', 'ticket_url', 'booking_url', 'external_links', 'facts', 'content_details',
        'seo', 'organizer_name', 'organizer_url', 'custom_location',
    ];

    public function __construct(private readonly EventReviewRule $review) {}

    /**
     * Crea la bozza, o aggiorna quella che c'è già (`$draft`): salvare due
     * volte non crea due eventi. Valida come il modulo rapido del pannello
     * (le chiavi d'errore hanno il prefisso `$prefix`).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $facebookImport  i dati letti da Facebook, se il modulo li ha importati
     */
    public function persist(Venue $venue, User $user, ?Event $draft, array $data, array $facebookImport = [], string $prefix = 'data.'): Event
    {
        $event = $draft ?? new Event;

        abort_unless($event->exists
            ? Gate::forUser($user)->allows('update', $event)
            : Gate::forUser($user)->allows('create', [Event::class, $venue]), 403);

        if (array_key_exists('description', $data)) {
            $data['description'] = EditorContent::clean('description', $data['description']);
        }
        if (array_key_exists('content_details', $data)) {
            $data['content_details'] = EditorContent::clean('content_details', $data['content_details']);
        }
        $rules = [
            ...EventFormRules::beforeGoing($prefix),
            ...EventFormRules::location($prefix),
            ...EventFormRules::basics($prefix, categoryRequired: true),
            ...EventFormRules::venueLinks($prefix),
        ];
        // La bozza non chiede né la data né il tipo di prezzo: solo titolo e categoria (che ha il ripiego).
        $rules[$prefix.'price_type'] = ['nullable', Rule::enum(PriceType::class)];
        // I tag non sono una colonna: li salva chi chiama (il campo del pannello o l'API).
        unset($rules[$prefix.'tags'], $rules[$prefix.'tags.*']);
        $data = $prefix === ''
            ? Validator::make($data, $rules)->validate()
            : Validator::make([rtrim($prefix, '.') => $data], $rules)->validate()[rtrim($prefix, '.')];

        if (isset($data['content_details'])) {
            $data['content_details'] = $this->withoutVenueDefaults($venue, $data['content_details']);
        }

        $data = $this->normalizeLocation($data);

        foreach (self::CREATE_COLUMNS as $column) {
            if (array_key_exists($column, $data)) {
                $event->setAttribute($column, $data[$column] === '' ? null : $data[$column]);
            }
        }

        if (! $event->exists) {
            $event->city_id = $venue->city_id;
            $event->venue_id = $venue->getKey();
            $event->created_by = $user->getKey();
            $event->source = EventSource::Venue;
            $event->status = EventStatus::Draft;
        }

        if ($facebookImport !== []) {
            $event->source_metadata = ['facebook' => $facebookImport];
            $event->source_ref = 'facebook:'.$facebookImport['id'];
        }

        $event->save();

        return $event;
    }

    /**
     * La categoria con cui tenere in piedi una bozza incompleta: quella
     * abituale del locale, altrimenti la prima del catalogo. Vive fino alla
     * pubblicazione, dove il campo è obbligatorio e la sovrascrive.
     */
    public function fallbackCategoryId(Venue $venue): ?int
    {
        $habit = VenueEventDefaults::formDefaults($venue)['category_id'] ?? null;

        if ($habit !== null) {
            return (int) $habit;
        }

        $first = Category::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->value('id');

        return $first === null ? null : (int) $first;
    }

    /**
     * La prima data. `business_date` ed `effective_ends_at` non si scrivono
     * qui: le calcola l'observer (§5 delle convenzioni). Gli orari arrivano
     * come istanti (UTC dal pannello, con lo scarto dall'app).
     */
    public function createFirstDate(Event $event, mixed $startsAt, mixed $endsAt): ?EventOccurrence
    {
        if (blank($startsAt)) {
            return null;
        }

        $occurrence = new EventOccurrence([
            'event_id' => $event->getKey(),
            'starts_at' => self::instant($startsAt),
            'ends_at' => blank($endsAt) ? null : self::instant($endsAt),
            'is_all_day' => false,
            'status' => OccurrenceStatus::Scheduled,
            'is_exception' => false,
        ]);

        $occurrence->setRelation('event', $event);
        $occurrence->save();

        return $occurrence;
    }

    /**
     * Un istante del modulo nel fuso dell'applicazione, quello in cui il
     * modello scrive le date: il pannello lo dà già così, l'app con il suo
     * scarto (`2026-12-05T21:00:00+01:00`).
     */
    public static function instant(mixed $value): Carbon
    {
        return Carbon::parse((string) $value)->setTimezone(config()->string('app.timezone'));
    }

    /**
     * Il locale impara le proprie abitudini: il prossimo evento parte già
     * compilato (§10.2).
     */
    public function rememberDefaults(Venue $venue, Event $event, ?EventOccurrence $occurrence): void
    {
        $local = $occurrence === null
            ? null
            : CarbonImmutable::instance($occurrence->starts_at)->setTimezone($venue->city->timezone);

        VenueEventDefaults::remember(
            $venue,
            [
                'category_id' => $event->category_id,
                'price_type' => $event->price_type,
                'price_min' => $event->price_min,
            ],
            $local?->hour,
            $local?->minute,
        );
    }

    /**
     * I dati della modifica, ridotti alle colonne del modulo, con lo stato
     * `pending` se il salvataggio rimanda l'evento in revisione. Restituisce
     * anche se è successo, per il messaggio «inviato alla redazione».
     *
     * `$otherNewContent` è il contenuto nuovo che non passa dai dati: una
     * locandina appena caricata o una voce del listino nuova o riscritta.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: bool}
     */
    public function prepareUpdate(Event $event, Venue $venue, User $user, array $data, bool $otherNewContent): array
    {
        // Solo colonne offerte dal modulo: i campi amministrativi e il tenant
        // non diventano scrivibili aggiungendoli a una richiesta.
        if (array_key_exists('custom_location', $data)) {
            $data = $this->normalizeLocation($data);
        }

        $filtered = Arr::only($data, self::EDIT_COLUMNS);

        /*
         * Un locale che non pubblica da sé passa dalla redazione anche quando
         * riscrive un evento già online: altrimenti la revisione varrebbe solo
         * per la prima versione, e testo e collegamenti cambiati dopo
         * andrebbero in pagina senza che nessuno li abbia letti. Prezzo e
         * categoria restano liberi: sono correzioni, non contenuto nuovo.
         */
        $sent = false;
        if ($this->review->sendsToReview($event, $venue, $user)
            && ($this->contentChanged($event, $filtered) || $otherNewContent)) {
            $filtered['status'] = EventStatus::Pending;
            $sent = true;
        }

        return [$filtered, $sent];
    }

    /**
     * Il salvataggio della modifica fatto dall'app: colonne, tag e listino in
     * una transazione, con la stessa regola di revisione del pannello.
     *
     * @param  array<string, mixed>  $data  i dati validati (chiavi assenti: non si toccano)
     * @return bool vero se l'evento è tornato in revisione
     */
    public function update(Event $event, Venue $venue, User $user, array $data): bool
    {
        Gate::forUser($user)->authorize('update', $event);

        if (array_key_exists('content_details', $data)) {
            $own = is_array($data['content_details']) ? $data['content_details'] : [];
            /*
             * Il modulo dell'app manda i valori del locale già compilati, come
             * il pannello: si tolgono qui, così l'evento li eredita e non li
             * copia. Le chiavi che l'app non manda (scritte da un import o
             * dalla redazione) restano.
             */
            $merged = array_replace(is_array($event->getAttribute('content_details')) ? $event->getAttribute('content_details') : [], $own);
            $data['content_details'] = $this->withoutVenueDefaults($venue, $merged);
        }

        $tiers = array_key_exists('ticket_tiers', $data) && is_array($data['ticket_tiers']) ? $data['ticket_tiers'] : null;
        $tags = array_key_exists('tags', $data) && is_array($data['tags']) ? array_map('intval', $data['tags']) : null;

        [$filtered, $sent] = $this->prepareUpdate($event, $venue, $user, $data, $tiers !== null && $this->tiersChanged($event, $tiers));

        DB::transaction(function () use ($event, $filtered, $tiers, $tags): void {
            $event->fill($filtered)->save();
            if ($tags !== null) {
                $event->tags()->sync($tags);
            }
            if ($tiers !== null) {
                $this->syncTicketTiers($event, $tiers);
            }
        });

        return $sent;
    }

    /**
     * Il listino dei biglietti ha nome, collegamento e nota che vanno in
     * scheda come il testo: una voce nuova o riscritta è contenuto nuovo.
     * Prezzo e disponibilità no, come prezzo e categoria dell'evento; togliere
     * una voce non porta niente in pagina.
     *
     * @param  array<int|string, mixed>  $state  le voci come le manda il modulo
     */
    public function tiersChanged(Event $event, array $state): bool
    {
        $key = fn (array $tier): string => (string) json_encode(array_map(
            fn (mixed $value): string => trim((string) (is_scalar($value) ? $value : '')),
            [$tier['name'] ?? '', $tier['url'] ?? '', $tier['note'] ?? ''],
        ));
        $current = $event->ticketTiers()->get(['name', 'url', 'note'])->map(fn ($tier): string => $key($tier->only(['name', 'url', 'note'])))->all();

        foreach ($state as $tier) {
            if (is_array($tier) && ! in_array($key($tier), $current, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Il listino dell'evento (le voci senza data), come lo salva il
     * ripetitore del pannello: le voci con `id` si aggiornano, le nuove si
     * creano, quelle sparite si cancellano, l'ordine è quello ricevuto. Le
     * voci di una singola data non si toccano: si governano dalle date.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function syncTicketTiers(Event $event, array $rows): void
    {
        $existing = $event->ticketTiers()->whereNull('occurrence_id')->get()->keyBy('id');
        $kept = [];

        foreach (array_values($rows) as $position => $row) {
            $values = [
                'name' => (string) $row['name'],
                'price' => isset($row['price']) && $row['price'] !== '' ? $row['price'] : null,
                'status' => $row['status'],
                'url' => filled($row['url'] ?? null) ? $row['url'] : null,
                'note' => filled($row['note'] ?? null) ? $row['note'] : null,
                'sort_order' => $position + 1,
            ];
            $tier = isset($row['id']) ? $existing->get((int) $row['id']) : null;
            if ($tier instanceof TicketTier) {
                $tier->fill($values)->save();
                $kept[] = (int) $tier->getKey();

                continue;
            }
            $created = $event->ticketTiers()->create([...$values, 'occurrence_id' => null]);
            $kept[] = (int) $created->getKey();
        }

        $existing->reject(fn (TicketTier $tier): bool => in_array((int) $tier->getKey(), $kept, true))
            ->each(fn (TicketTier $tier) => $tier->delete());
    }

    /**
     * «Prima di andare»: i valori uguali a quelli del locale non si copiano
     * sull'evento, lo ereditano.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    public function withoutVenueDefaults(Venue $venue, array $details): array
    {
        $defaults = BeforeGoingDefaults::forVenue($venue);
        foreach (BeforeGoingDefaults::FIELDS as $field) {
            if (array_key_exists($field, $details)) {
                $details[$field] = BeforeGoingDefaults::override($field, $details[$field], $defaults[$field] ?? null);
            }
        }

        return $details;
    }

    /**
     * Un indirizzo vuoto toglie il luogo; le coordinate restano numeri o
     * niente.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeLocation(array $data): array
    {
        if (array_key_exists('custom_location', $data) && blank($data['custom_location']['address'] ?? null)) {
            $data['custom_location'] = null;
        }
        if (is_array($data['custom_location'] ?? null)) {
            foreach (['lat', 'lng'] as $coordinate) {
                $data['custom_location'][$coordinate] = filled($data['custom_location'][$coordinate] ?? null) ? (float) $data['custom_location'][$coordinate] : null;
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function contentChanged(Event $record, array $data): bool
    {
        foreach (array_intersect(self::CONTENT_FIELDS, array_keys($data)) as $field) {
            $current = $record->getAttribute($field);
            /*
             * Si confronta solo quello che il modulo del locale mostra: le
             * chiavi che non ha (l'indicizzazione, riservata alla redazione e
             * rimessa dal modello a ogni salvataggio, o ciò che ha scritto un
             * import) non sono un cambiamento di chi salva. Le liste no: lì
             * una voce in meno è contenuto tolto.
             */
            if (is_array($current) && is_array($data[$field]) && ! array_is_list($data[$field])) {
                $current = array_intersect_key($current, $data[$field]);
            }
            if (json_encode(self::normalize($current)) !== json_encode(self::normalize($data[$field]))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Il testo come lo legge il pubblico, più i collegamenti: l'editor riscrive
     * a modo suo una descrizione salvata come testo semplice (paragrafi,
     * spazi), e quello non è un cambiamento. Un indirizzo cambiato dentro un
     * link sì, anche se la parola resta la stessa.
     */
    private static function text(mixed $value): string
    {
        $html = (string) (is_scalar($value) ? $value : '');
        preg_match_all('/\b(?:href|src)\s*=\s*["\']([^"\']*)["\']/i', $html, $links);

        return (string) preg_replace('/\s+/u', '', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)).'|'.implode(' ', $links[1]);
    }

    /** Riduce un valore al suo contenuto, senza vuoti e con le chiavi in ordine: lo stesso contenuto, scritto due volte, è uguale. */
    private static function normalize(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = json_decode((string) json_encode($value), true);
        }
        if (! is_array($value)) {
            // Un paragrafo vuoto dell'editor non è un testo, e 45.4 vale "45.4".
            $text = self::text($value);

            return $text === '|' ? null : $text;
        }
        $list = array_is_list($value);
        $value = array_filter(array_map(self::normalize(...), $value), fn (mixed $item): bool => $item !== null);
        if ($list) {
            $value = array_values($value);
        } else {
            ksort($value);
        }

        return $value === [] ? null : $value;
    }
}
