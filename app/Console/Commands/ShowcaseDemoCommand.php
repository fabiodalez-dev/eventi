<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Showcase\ShowcaseRides;
use App\Enums\CatalogReviewStatus;
use App\Enums\CommunityStatus;
use App\Enums\EventCommentStatus;
use App\Enums\PostIntent;
use App\Enums\ProfileVisibility;
use App\Enums\SavedVisibility;
use App\Enums\VerificationStatus;
use App\Models\CatalogRating;
use App\Models\CatalogReview;
use App\Models\Category;
use App\Models\City;
use App\Models\CommunityComment;
use App\Models\CommunityPost;
use App\Models\CommunityProfile;
use App\Models\Event;
use App\Models\EventComment;
use App\Models\EventCommentReaction;
use App\Models\EventFeature;
use App\Models\EventOccurrence;
use App\Models\Follow;
use App\Models\Organizer;
use App\Models\Report;
use App\Models\SavedEvent;
use App\Models\User;
use App\Models\UserBlock;
use App\Models\Venue;
use App\Notifications\CommunityNotification;
use App\Services\Community\WhatsappVerification;
use App\Support\ContentVersion;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Popola una città con una settimana dimostrativa completa: eventi della
 * settimana prossima, persone con WhatsApp verificato, profili, relazioni,
 * post, commenti, reazioni, recensioni e passaggi in auto verso gli eventi.
 * Serve a mostrare le funzioni nuove, non a simulare traffico reale.
 *
 * Stesso modello di sicurezza di `events:investor-demo`: fuori da local e
 * testing serve `--allow-production`, un lock impedisce due esecuzioni
 * parallele, una seconda esecuzione non duplica niente e completa ciò che
 * manca. Tutto è riconoscibile: eventi `is_demo` con `source_ref` che inizia
 * per PREFIX, persone con email nel dominio riservato `.invalid` — che
 * `User::canReceiveNotifications()` esclude da ogni invio — preferenze di
 * notifica spente, nessun dispositivo, nessuna iscrizione push.
 *
 * Nessuna scrittura passa dalle Action che notificano (PostComment,
 * Community::comment, Community::follow): avviserebbero lo staff reale dei
 * locali. Le righe si scrivono direttamente, con gli stessi valori che quelle
 * Action produrrebbero. Gli eventi `is_demo` non annunciano nulla neanche da
 * soli: `NotificationScheduler` li ignora, i riepiloghi e i caroselli social
 * li escludono (`EventOccurrenceQuery::excludingDemo()`), e restano «non
 * verificati» anche nei locali verificati (`EventObserver::saving`).
 *
 * Gli eventi stanno in locali veri, come quelli di `events:investor-demo`,
 * con l'avviso «non confermato dal locale» nella scheda. Le recensioni no:
 * un voto a cinque stelle di una persona inesistente su un'attività vera è
 * una recensione falsa, e resta **in attesa di moderazione** — si vede nel
 * pannello, non nella scheda pubblica del locale.
 *
 * I passaggi hanno la loro classe, `ShowcaseRides`, con lo stesso principio:
 * righe scritte direttamente, ma con i vincoli del servizio, così la
 * manutenzione dei passaggi le tratta come vere e non le annulla.
 *
 * `--purge` rimuove le persone del catalogo (con tutto ciò che dipende da
 * loro, passaggi compresi) e gli eventi con il prefisso. Le chiavi esterne
 * in cascata portano via anche ciò che altri utenti hanno agganciato lì — un
 * salvataggio su una data demo, una risposta a un commento demo, una
 * richiesta di posto a un conducente demo — e il resoconto lo conta, voce
 * per voce, invece di negarlo. Se però una persona vera ha un passaggio
 * ancora da fare su una data della vetrina, offerto o con un posto
 * accettato, `--purge` si ferma e lo elenca: cancellarlo lascerebbe
 * qualcuno a piedi. Solo `--force-real` lo porta via lo stesso.
 */
final class ShowcaseDemoCommand extends Command
{
    public const PREFIX = 'showcase-demo-v1:';

    public const EMAIL_DOMAIN = 'demo.incitta.invalid';

    /**
     * Preferenze delle persone demo: niente promemoria né riepiloghi, e ogni
     * avviso resta in app. `delivery = database` fa chiudere a
     * `CommunityDelivery` le righe della coda senza inviarle.
     */
    private const QUIET_PREFERENCES = ['reminders' => false, 'sold_out' => false, 'venue_digest' => false, 'daily_digest' => false, 'comments' => false, 'delivery' => 'database'];

    protected $signature = 'demo:showcase {city=padova} {--dry-run} {--allow-production} {--purge : Rimuove tutto ciò che questo comando ha creato} {--force-real : Con --purge, rimuove anche i passaggi futuri e i posti accettati delle persone vere sulle date della vetrina} {--week= : Lunedì della settimana della vetrina (AAAA-MM-GG); di norma il primo lunedì da oggi compreso}';

    protected $description = 'Popola la città con una settimana dimostrativa: 25 eventi, persone verificate, post, commenti, reazioni, recensioni e passaggi';

    /** @var array<string, array{0: int, 1: int|string}> */
    private array $report = [];

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) && ! $this->option('allow-production')) {
            $this->error('È richiesta l’autorizzazione --allow-production.');

            return self::FAILURE;
        }

        $city = City::query()->active()->where('slug', $this->argument('city'))->firstOrFail();
        $catalog = self::catalog();

        if ($this->option('purge')) {
            return $this->purge($city, $catalog);
        }

        $categories = Category::query()->get()->keyBy('slug');
        $credits = json_decode((string) file_get_contents(database_path('seeders/investor-media/credits.json')), true, 512, JSON_THROW_ON_ERROR);
        $venues = Venue::query()->approved()->where('city_id', $city->id)->orderBy('id')->get();
        $this->validate($catalog, $categories, $credits, $venues);

        $week = $this->weekStart($city);
        $existingEvents = Event::withTrashed()->where('city_id', $city->id)->where('source_ref', 'like', self::PREFIX.'%')->count();
        $existingPeople = User::withTrashed()->whereIn('email', self::emails($catalog))->count();
        $this->info(sprintf('Catalogo verificato: %d eventi dal %s al %s, %d persone. Già presenti: %d eventi, %d persone.',
            count($catalog['events']), $week->format('d/m/Y'), $week->addDays(6)->format('d/m/Y'), count($catalog['people']), $existingEvents, $existingPeople));

        if ($this->option('dry-run')) {
            $investor = $this->investorOccurrences($city, $week)->count();
            $this->table(['Elemento', 'Previsti'], [
                ['Eventi della settimana prossima', count($catalog['events'])],
                ['Persone con WhatsApp verificato', count($catalog['people'])],
                ['Post (su eventi demo e del catalogo investitori)', count(array_filter($catalog['posts'], fn (array $p): bool => is_int($p[1]))).' + '.min($investor, count(array_filter($catalog['posts'], fn (array $p): bool => ! is_int($p[1]))))],
                ['Commenti ai post', count($catalog['post_comments'])],
                ['Commenti agli eventi (con risposte)', array_sum(array_map(fn (array $c): int => 1 + count($c[3]), $catalog['event_comments']))],
                ['Reazioni ai commenti', array_sum(array_map(fn (array $c): int => count($c[4]), $catalog['event_comments']))],
                ['Recensioni dei locali (in attesa di moderazione)', count($catalog['venue_reviews'])],
                ['Passaggi offerti (conducenti diversi)', count($catalog['rides']).' ('.count(array_unique(array_column($catalog['rides'], 0))).')'],
                ['Richieste di passaggio (accettate, in attesa, rifiutate o ritirate)', count($catalog['ride_requests']).' ('.implode(', ', array_map(
                    fn (string $status): int => count(array_filter($catalog['ride_requests'], fn (array $r): bool => $r[3] === $status)), ['accepted', 'pending', 'declined', 'withdrawn'])).')'],
                ['Messaggi nelle chat dei passaggi', count($catalog['ride_messages'])],
                ['Recensioni ai conducenti (su date investitori concluse)', min(count($catalog['ride_reviews']), app(ShowcaseRides::class)->pastDates($this->allInvestorOccurrences($city), count($catalog['ride_reviews']))->count())],
            ]);
            $this->line('Prova a vuoto: nessuna scrittura.');

            return self::SUCCESS;
        }

        if ((string) config('community.phone_hash_key') === '') {
            $this->error('WHATSAPP_PHONE_HASH_KEY non è configurata: senza impronta le persone non risultano verificate.');

            return self::FAILURE;
        }

        return Cache::lock('showcase-demo:'.$city->id, 3600)->block(5, function () use ($city, $catalog, $categories, $credits, $venues, $week): int {
            $events = $this->seedEvents($city, $catalog['events'], $categories, $credits, $venues, $week);
            $this->keepDemoEventsUnverified($city);
            $eventVenues = $this->venuesOf($events);
            $people = $this->seedPeople($city, $catalog['people']);
            $this->seedFollows($city, $people, $eventVenues);
            $this->seedBlocks($people);
            $posts = $this->seedPosts($city, $week, $catalog['posts'], $people, $events);
            $this->seedPrivateSaves($people, $events);
            $this->seedPostComments($catalog['post_comments'], $people, $posts);
            $this->seedEventComments($catalog['event_comments'], $people, $events);
            $this->seedVenueReviews($catalog['venue_reviews'], $people, $events);
            $this->seedRides($city, $catalog, $people, $events);
            ContentVersion::bump($city);

            $this->table(['Elemento', 'Creati ora', 'Totale demo'], array_map(
                fn (string $label, array $row): array => [$label, $row[0], $row[1]],
                array_keys($this->report), $this->report,
            ));
            $this->info('Vetrina pronta. Per rimuoverla: php artisan demo:showcase '.$city->slug.' --purge');

            return self::SUCCESS;
        });
    }

    /**
     * @return array{events: list<array<string, mixed>>, people: list<array{handle: string, name: string, bio: string, visibility: string, featured: bool}>, posts: list<array{0: int, 1: int|string, 2: string, 3: string}>, post_comments: list<array{0: int, 1: int, 2: string, 3: int|null}>, event_comments: list<array{0: int, 1: int, 2: string, 3: list<array{0: int, 1: string}>, 4: list<array{0: int, 1: string}>}>, venue_reviews: list<array{0: int, 1: int, 2: int, 3: string}>, rides: list<array{0: int, 1: int, 2: string, 3: string, 4: string, 5: int, 6: array<string, mixed>}>, ride_requests: list<array{0: int, 1: int, 2: int, 3: string, 4: string|null}>, ride_messages: list<array{0: int, 1: string, 2: string}>, ride_reviews: list<array{0: int, 1: int, 2: string, 3: int, 4: string}>}
     */
    public static function catalog(): array
    {
        /** @var array{events: list<array<string, mixed>>, people: list<array{handle: string, name: string, bio: string, visibility: string, featured: bool}>, posts: list<array{0: int, 1: int|string, 2: string, 3: string}>, post_comments: list<array{0: int, 1: int, 2: string, 3: int|null}>, event_comments: list<array{0: int, 1: int, 2: string, 3: list<array{0: int, 1: string}>, 4: list<array{0: int, 1: string}>}>, venue_reviews: list<array{0: int, 1: int, 2: int, 3: string}>, rides: list<array{0: int, 1: int, 2: string, 3: string, 4: string, 5: int, 6: array<string, mixed>}>, ride_requests: list<array{0: int, 1: int, 2: int, 3: string, 4: string|null}>, ride_messages: list<array{0: int, 1: string, 2: string}>, ride_reviews: list<array{0: int, 1: int, 2: string, 3: int, 4: string}>} $catalog */
        $catalog = require database_path('seeders/data/showcase-demo.php');

        return $catalog;
    }

    /**
     * Gli indirizzi delle persone del catalogo: sono l'unica chiave con cui
     * `--purge` le ritrova, così non tocca gli account di altri comandi demo
     * che usano lo stesso dominio.
     *
     * @param  array{people: list<array{handle: string}>}  $catalog
     * @return list<string>
     */
    public static function emails(array $catalog): array
    {
        return array_map(fn (array $person): string => $person['handle'].'@'.self::EMAIL_DOMAIN, $catalog['people']);
    }

    /** Un numero italiano nell'intervallo +39 000…, che nessun operatore assegna. */
    public static function phone(int $index): string
    {
        return '+3900000000'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Il lunedì della settimana della vetrina, nel fuso della città.
     *
     * Se il catalogo è già stato importato si riparte dalla sua settimana:
     * una seconda esecuzione completa quella, non ne apre un'altra. Altrimenti
     * vale `--week`, oppure il primo lunedì da oggi **compreso**: chi lancia
     * il comando di lunedì mattina vuole la settimana che sta cominciando,
     * non aspettare sette giorni senza niente in cartellone. Il catalogo è
     * scritto sui giorni della settimana («Lunedì d'essai», «Venerdì
     * elettronico») e non si può far scivolare di un giorno.
     */
    private function weekStart(City $city): CarbonImmutable
    {
        $first = EventOccurrence::query()
            ->whereIn('event_id', Event::withTrashed()->where('city_id', $city->id)->where('source_ref', 'like', self::PREFIX.'%')->select('id'))
            ->orderBy('starts_at')->value('starts_at');
        $requested = $this->requestedWeek($city);

        if ($first !== null) {
            $existing = CarbonImmutable::parse($first)->timezone($city->timezone)->startOfWeek(CarbonImmutable::MONDAY);
            if ($requested !== null && ! $requested->equalTo($existing)) {
                throw new \RuntimeException(sprintf('La vetrina è già sulla settimana del %s: per spostarla serve prima --purge.', $existing->format('d/m/Y')));
            }

            return $existing;
        }

        $today = CarbonImmutable::now($city->timezone)->startOfDay();

        return $requested ?? ($today->isMonday() ? $today : $today->next(CarbonImmutable::MONDAY));
    }

    private function requestedWeek(City $city): ?CarbonImmutable
    {
        $option = $this->option('week');
        if (! is_string($option) || $option === '') {
            return null;
        }
        try {
            $week = CarbonImmutable::createFromFormat('Y-m-d', $option, $city->timezone)->startOfDay();
        } catch (InvalidFormatException) {
            $week = null;
        }
        if ($week === null || $week->format('Y-m-d') !== $option || ! $week->isMonday()) {
            throw new \RuntimeException('--week vuole un lunedì nel formato AAAA-MM-GG.');
        }

        return $week;
    }

    /**
     * Gli eventi dimostrativi della città non sono confermati da nessuno.
     * `EventObserver::saving` lo garantisce per quelli nuovi; questo passaggio
     * riallinea quelli già in archivio — il catalogo investitori nei locali
     * verificati aveva preso il badge «confermato dal locale».
     */
    private function keepDemoEventsUnverified(City $city): void
    {
        $demo = Event::query()->where('city_id', $city->id)->where('is_demo', true);
        $fixed = (clone $demo)->where('verification_status', '!=', VerificationStatus::Unverified->value)
            ->update(['verification_status' => VerificationStatus::Unverified->value]);
        $this->report['Eventi demo riportati a «non verificato»'] = [$fixed, $demo->count()];
    }

    /**
     * I locali degli eventi della vetrina, nell'ordine del catalogo e senza
     * ripetizioni. Sono la chiave stabile per relazioni e recensioni: il
     * locale di un evento non cambia fra un'esecuzione e l'altra, mentre una
     * posizione fra «tutti gli approvati» slitta appena la redazione ne
     * approva uno nuovo.
     *
     * @param  array<int, EventOccurrence>  $events
     * @return Collection<int, Venue>
     */
    private function venuesOf(array $events): Collection
    {
        $ids = array_values(array_unique(array_map(fn (EventOccurrence $occurrence): int => (int) $occurrence->venue_id, $events)));

        return Venue::query()->whereIn('id', $ids)->get()->sortBy(fn (Venue $venue): int => (int) array_search((int) $venue->id, $ids, true))->values();
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @param  \Illuminate\Support\Collection<string, Category>  $categories
     * @param  array<string, array<string, mixed>>  $credits
     * @param  Collection<int, Venue>  $venues
     */
    private function validate(array $catalog, \Illuminate\Support\Collection $categories, array $credits, Collection $venues): void
    {
        $titles = array_column($catalog['events'], 'title');
        $handles = array_column($catalog['people'], 'handle');
        if (count($titles) !== 25 || count(array_unique($titles)) !== 25) {
            throw new \RuntimeException('Il catalogo deve contenere 25 titoli unici.');
        }
        if (count(array_unique($handles)) !== count($handles) || preg_grep('/^[a-z0-9_]{3,40}$/', $handles, PREG_GREP_INVERT) !== []) {
            throw new \RuntimeException('Gli handle delle persone devono essere unici e validi.');
        }
        if ($venues->isEmpty()) {
            throw new \RuntimeException('Nessun locale approvato nella città.');
        }
        ShowcaseRides::validate($catalog);
        foreach ($catalog['events'] as $row) {
            if (! isset($categories[$row['category']], $credits[$row['category']]) || ! is_file(database_path('seeders/investor-media/'.$credits[$row['category']]['file']))) {
                throw new \RuntimeException('Categoria o fotografia mancante: '.$row['title']);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  \Illuminate\Support\Collection<string, Category>  $categories
     * @param  array<string, array<string, mixed>>  $credits
     * @param  Collection<int, Venue>  $venues
     * @return array<int, EventOccurrence>
     */
    private function seedEvents(City $city, array $rows, \Illuminate\Support\Collection $categories, array $credits, Collection $venues, CarbonImmutable $week): array
    {
        $features = EventFeature::query()->pluck('id', 'slug')->map(fn ($id): int => (int) $id)->all();
        $created = 0;
        $occurrences = [];
        foreach ($rows as $index => $row) {
            $ref = self::PREFIX.str_pad((string) $index, 2, '0', STR_PAD_LEFT);
            $event = Event::withTrashed()->where('city_id', $city->id)->where('source_ref', $ref)->first();
            if ($event?->trashed()) {
                continue; // Una cancellazione della redazione si rispetta.
            }
            $credit = $credits[$row['category']];
            if ($event === null) {
                $matching = $venues->filter(fn (Venue $venue): bool => in_array($venue->type->value, $row['venue_types'], true))->values();
                $pool = $matching->isEmpty() ? $venues : $matching;
                $venue = $pool[$index % $pool->count()];
                [$hour, $minute] = array_map('intval', explode(':', $row['time']));
                $start = $week->addDays($row['day'])->setTime($hour, $minute);
                $paid = $row['price'] > 0;
                $details = [
                    'feature_ids' => array_values(array_intersect_key($features, array_flip($row['features']))),
                    'accessibility' => $row['accessible'] ? 'yes' : 'no',
                    'accessibility_notes' => $row['accessible'] ? 'Ingresso senza gradini e bagno accessibile. Assistenza disponibile all’ingresso.' : 'Il locale ha alcuni gradini all’ingresso: chiedere assistenza allo staff.',
                    'age_groups' => ($row['family'] ?? false) ? ['3-5', '6-10'] : ['18-plus'],
                    'stroller' => ($row['family'] ?? false) ? 'yes' : 'no',
                ];
                $event = DB::transaction(function () use ($city, $venue, $categories, $row, $ref, $start, $paid, $details): Event {
                    $event = Event::create([
                        'city_id' => $city->id, 'venue_id' => $venue->id, 'category_id' => $categories[$row['category']]->id,
                        'title' => $row['title'],
                        'description' => $row['description']."\n\nEvento dimostrativo: appuntamento fittizio per presentare inCittà, non confermato dal locale. Non è possibile prenotare.",
                        'short_description' => Str::limit($row['description'], 450),
                        'source' => 'manual', 'source_ref' => $ref, 'status' => 'published', 'published_at' => now(),
                        'verification_status' => 'unverified', 'is_demo' => true, 'content_details' => $details,
                        'is_outdoor' => in_array('allaperto', $row['features'], true),
                        'price_type' => $paid ? 'ticket' : 'free', 'price_min' => $row['price'],
                        'price_notes' => $paid ? 'Pagamento all’ingresso.' : 'Ingresso libero.',
                        'currency' => 'EUR', 'booking_required' => false, 'language' => 'it',
                    ]);
                    $event->occurrences()->create(['venue_id' => $venue->id, 'starts_at' => $start->utc(), 'ends_at' => $start->addMinutes(120)->utc(), 'status' => 'scheduled', 'is_all_day' => false, 'booking_enabled' => false]);

                    return $event;
                });
                $created++;
            }
            if (! $event->hasMedia('poster')) {
                $event->addMedia(database_path('seeders/investor-media/'.$credit['file']))->preservingOriginal()->withCustomProperties(['illustrative' => true, 'credit' => $credit])->toMediaCollection('poster');
            }
            $occurrence = $event->occurrences()->orderBy('starts_at')->first();
            if ($occurrence !== null) {
                $occurrences[$index] = $occurrence;
            }
        }
        $this->report['Eventi della settimana prossima'] = [$created, count($occurrences)];

        return $occurrences;
    }

    /**
     * @param  list<array{handle: string, name: string, bio: string, visibility: string, featured: bool}>  $rows
     * @return array<int, User>
     */
    private function seedPeople(City $city, array $rows): array
    {
        $fingerprints = app(WhatsappVerification::class);
        $people = [];
        $created = 0;
        $profiles = 0;
        foreach ($rows as $index => $row) {
            $email = $row['handle'].'@'.self::EMAIL_DOMAIN;
            $user = User::withTrashed()->where('email', $email)->first();
            if ($user?->trashed()) {
                continue; // Account cancellato dall'amministrazione: non si ricrea.
            }
            $taken = CommunityProfile::query()->where('handle', $row['handle'])->when($user !== null, fn ($q) => $q->where('user_id', '!=', $user->id))->exists();
            if ($taken) {
                $this->warn('Handle già usato da un altro account, persona saltata: '.$row['handle']);

                continue;
            }
            if ($user === null) {
                [$first, $last] = explode(' ', $row['name'], 2);
                $user = User::create([
                    'name' => $row['name'], 'first_name' => $first, 'last_name' => $last, 'email' => $email,
                    // Casuale e mai comunicata: l'account non è utilizzabile per entrare.
                    'password' => Str::random(64), 'city_id' => $city->id, 'timezone' => $city->timezone, 'locale' => 'it',
                    'notification_preferences' => self::QUIET_PREFERENCES,
                ]);
                $phone = self::phone($index);
                $verifiedAt = CarbonImmutable::now()->subDays(20 - $index % 15);
                $user->forceFill([
                    'email_verified_at' => $verifiedAt, 'whatsapp_phone' => $phone, 'whatsapp_phone_hash' => $fingerprints->fingerprint($phone),
                    'whatsapp_verified_at' => $verifiedAt, 'whatsapp_prompted_at' => $verifiedAt, 'last_active_at' => now()->subHours($index),
                ])->save();
                $created++;
            }
            // Anche gli account creati da una versione precedente: solo avvisi in app, mai push né email.
            if (array_intersect_key($user->notification_preferences ?? [], self::QUIET_PREFERENCES) !== self::QUIET_PREFERENCES) {
                $user->forceFill(['notification_preferences' => [...($user->notification_preferences ?? []), ...self::QUIET_PREFERENCES]])->save();
            }
            if (! $user->communityProfile()->exists()) {
                $profile = new CommunityProfile(['handle' => $row['handle'], 'display_name' => $row['name'], 'bio' => $row['bio'].' Profilo dimostrativo.',
                    'city_id' => $city->id, 'visibility' => ProfileVisibility::from($row['visibility']), 'indexable' => false]);
                $profile->featured = $row['featured'];
                $user->communityProfile()->save($profile);
                $profiles++;
            }
            $people[$index] = $user;
        }
        $this->report['Persone verificate WhatsApp'] = [$created, count($people)];
        $this->report['Profili community'] = [$profiles, CommunityProfile::query()->whereIn('user_id', array_map(fn (User $u): int => $u->id, $people))->count()];

        return $people;
    }

    /**
     * Persone che seguono persone (vicini di elenco reciproci, più qualche
     * relazione a senso unico) e persone che seguono locali e organizzatori.
     * I locali seguiti sono quelli degli eventi della vetrina (`venuesOf()`),
     * e non notificano: `notify` resta spento.
     *
     * @param  array<int, User>  $people
     * @param  Collection<int, Venue>  $venues
     */
    private function seedFollows(City $city, array $people, Collection $venues): void
    {
        $count = count(self::catalog()['people']);
        $morph = (new User)->getMorphClass();
        $created = 0;
        $notified = 0;
        foreach ($people as $index => $user) {
            $offsets = $index % 2 === 0 ? [1, $count - 1, 5, 9] : [1, $count - 1, 5];
            foreach ($offsets as $offset) {
                $target = $people[($index + $offset) % $count] ?? null;
                if ($target === null || $target->id === $user->id) {
                    continue;
                }
                $row = DB::table('followables')->where('user_id', $user->id)->where('followable_type', $morph)->where('followable_id', $target->id)->exists();
                if (! $row) {
                    $at = now()->subDays(($index + $offset) % 12 + 1);
                    DB::table('followables')->insert(['user_id' => $user->id, 'followable_type' => $morph, 'followable_id' => $target->id, 'accepted_at' => $at, 'created_at' => $at, 'updated_at' => $at]);
                    $created++;
                    $notified += $this->notify($target, 'follow', Route::has('community.followers') ? route('community.followers') : '/', $user->id);
                }
            }
        }
        $ids = array_map(fn (User $u): int => $u->id, $people);
        $this->report['Relazioni tra persone'] = [$created, DB::table('followables')->where('followable_type', $morph)->whereIn('user_id', $ids)->count()];

        $organizers = Organizer::query()->where('is_active', true)->where('city_id', $city->id)->orderBy('id')->limit(3)->get();
        $catalogFollows = 0;
        foreach ($people as $index => $user) {
            $chosen = [];
            for ($k = 0; $k < 2 + $index % 3; $k++) {
                $venue = $venues[($index * 3 + $k) % $venues->count()];
                $chosen[$venue->id] = $venue;
            }
            foreach ($chosen as $venue) {
                $catalogFollows += (int) Follow::query()->firstOrCreate(['user_id' => $user->id, 'followable_type' => 'venue', 'followable_id' => $venue->id], ['notify' => false])->wasRecentlyCreated;
            }
            foreach ($organizers as $position => $organizer) {
                if (($index + $position) % 3 === 0) {
                    $catalogFollows += (int) Follow::query()->firstOrCreate(['user_id' => $user->id, 'followable_type' => 'organizer', 'followable_id' => $organizer->id], ['notify' => false])->wasRecentlyCreated;
                }
            }
            // I locali in vetrina sul profilo sono scelti fra quelli seguiti, come nel servizio.
            $profile = $user->communityProfile;
            if ($profile !== null && $index % 3 !== 2 && ! $profile->venues()->exists()) {
                $profile->venues()->sync(array_slice(array_keys($chosen), 0, 1 + $index % 2));
            }
        }
        $this->report['Locali e organizzatori seguiti'] = [$catalogFollows, Follow::query()->whereIn('user_id', $ids)->count()];
        $this->report['Avvisi in app (seguaci, commenti)'] = [$notified, 0];
    }

    /**
     * Un blocco, fra due persone che non interagiscono: mostra la funzione in amministrazione.
     *
     * @param  array<int, User>  $people
     */
    private function seedBlocks(array $people): void
    {
        $created = 0;
        if (isset($people[16], $people[8])) {
            $created = (int) UserBlock::query()->firstOrCreate(['user_id' => $people[16]->id, 'blocked_user_id' => $people[8]->id])->wasRecentlyCreated;
        }
        $this->report['Blocchi'] = [$created, UserBlock::query()->whereIn('user_id', array_map(fn (User $u): int => $u->id, $people))->count()];
    }

    /**
     * Le date del catalogo investitori dalla settimana della vetrina in poi,
     * se è stato importato. Il punto di partenza è la settimana e non «adesso»:
     * così una seconda esecuzione, giorni dopo, ritrova le stesse date.
     *
     * @return Builder<EventOccurrence>
     */
    private function investorOccurrences(City $city, CarbonImmutable $week): Builder
    {
        return EventOccurrence::query()
            ->whereIn('event_id', Event::query()->where('city_id', $city->id)->where('status', 'published')->where('source_ref', 'like', InvestorDemoCommand::PREFIX.'%')->select('id'))
            ->where('starts_at', '>=', $week->utc())->orderBy('starts_at')->orderBy('id');
    }

    /**
     * @param  list<array{0: int, 1: int|string, 2: string, 3: string}>  $rows
     * @param  array<int, User>  $people
     * @param  array<int, EventOccurrence>  $events
     * @return array<int, CommunityPost>
     */
    private function seedPosts(City $city, CarbonImmutable $week, array $rows, array $people, array $events): array
    {
        $investor = $this->investorOccurrences($city, $week)->limit(count($rows))->get()->values();
        $posts = [];
        $created = 0;
        $skipped = 0;
        foreach ($rows as $index => [$person, $target, $intent, $body]) {
            $occurrence = is_int($target) ? ($events[$target] ?? null) : ($investor[(int) Str::after($target, 'investor:')] ?? null);
            $user = $people[$person] ?? null;
            if ($occurrence === null || $user === null) {
                $skipped++;

                continue;
            }
            $at = CarbonImmutable::now()->subHours(3 + ($index * 7) % 90);
            $saved = SavedEvent::query()->firstOrCreate(['user_id' => $user->id, 'occurrence_id' => $occurrence->id]);
            $saved->forceFill(['visibility' => SavedVisibility::Public])->save();
            /* «Parteciperò» è un post, ma dice anche che quella persona ci va:
               dal modello separato la partecipazione è una riga sua, e senza
               questa la vetrina mostrerebbe il trafiletto e non il nome fra chi
               ci va. Un consiglio invece resta solo un consiglio. */
            if ($intent === PostIntent::Attend->value) {
                DB::table('community_attendances')->insertOrIgnore(['user_id' => $user->id, 'occurrence_id' => $occurrence->id,
                    'created_at' => $at, 'updated_at' => $at]);
            }
            $post = CommunityPost::query()->firstOrNew(['saved_event_id' => $saved->id]);
            if (! $post->exists) {
                $post->forceFill(['user_id' => $user->id, 'occurrence_id' => $occurrence->id, 'body' => $body, 'intent' => PostIntent::from($intent),
                    'status' => CommunityStatus::Published, 'published_at' => $at, 'created_at' => $at, 'updated_at' => $at])->save();
                $created++;
            }
            $posts[$index] = $post;
        }
        $ids = array_map(fn (User $u): int => $u->id, $people);
        $this->report['Post pubblici'] = [$created, CommunityPost::query()->whereIn('user_id', $ids)->count()];
        if ($skipped > 0) {
            $this->warn("Post saltati: {$skipped} (catalogo investitori assente o date concluse).");
        }

        return $posts;
    }

    /**
     * Salvataggi privati: la maggior parte delle persone tiene per sé qualcosa.
     *
     * @param  array<int, User>  $people
     * @param  array<int, EventOccurrence>  $events
     */
    private function seedPrivateSaves(array $people, array $events): void
    {
        $created = 0;
        foreach ($people as $index => $user) {
            foreach ([($index * 2 + 1) % 25, ($index * 5 + 3) % 25] as $position) {
                $occurrence = $events[$position] ?? null;
                if ($occurrence !== null) {
                    // Se la data è già pubblica per un post, firstOrCreate la lascia com'è.
                    $created += (int) SavedEvent::query()->firstOrCreate(['user_id' => $user->id, 'occurrence_id' => $occurrence->id])->wasRecentlyCreated;
                }
            }
        }
        $ids = array_map(fn (User $u): int => $u->id, $people);
        $this->report['Salvataggi privati'] = [$created, SavedEvent::query()->whereIn('user_id', $ids)->where('visibility', SavedVisibility::Private->value)->count()];
    }

    /**
     * @param  list<array{0: int, 1: int, 2: string, 3: int|null}>  $rows
     * @param  array<int, User>  $people
     * @param  array<int, CommunityPost>  $posts
     */
    private function seedPostComments(array $rows, array $people, array $posts): void
    {
        $comments = [];
        $created = 0;
        $notified = 0;
        foreach ($rows as $index => [$postIndex, $person, $body, $parent]) {
            $post = $posts[$postIndex] ?? null;
            $user = $people[$person] ?? null;
            if ($post === null || $user === null || ($parent !== null && ! isset($comments[$parent]))) {
                continue;
            }
            $parentId = $parent === null ? null : $comments[$parent]->id;
            $comment = CommunityComment::query()->where('community_post_id', $post->id)->where('user_id', $user->id)->where('body', $body)->first();
            if ($comment === null) {
                $at = CarbonImmutable::parse($post->published_at)->addMinutes(20 + $index * 13);
                $comment = CommunityComment::query()->create(['community_post_id' => $post->id, 'user_id' => $user->id, 'body' => $body,
                    'parent_id' => $parentId, 'status' => CommunityStatus::Published, 'created_at' => $at, 'updated_at' => $at]);
                $created++;
                $recipients = array_unique(array_filter([$post->user_id, $parent === null ? null : $comments[$parent]->user_id]));
                foreach ($recipients as $recipient) {
                    $person = collect($people)->firstWhere('id', $recipient);
                    if ($person instanceof User && $recipient !== $user->id) {
                        $notified += $this->notify($person, 'comment', Route::has('community.post') ? route('community.post', $post) : '/');
                    }
                }
            }
            $comments[$index] = $comment;
        }
        $ids = array_map(fn (User $u): int => $u->id, $people);
        $this->report['Commenti ai post'] = [$created, CommunityComment::query()->whereIn('user_id', $ids)->count()];
        $this->report['Avvisi in app (seguaci, commenti)'][0] += $notified;
        $this->report['Avvisi in app (seguaci, commenti)'][1] = DB::table('notifications')->where('notifiable_type', (new User)->getMorphClass())->whereIn('notifiable_id', $ids)->count();
    }

    /**
     * I commenti pubblici alle schede degli eventi, con risposte e reazioni.
     *
     * @param  list<array{0: int, 1: int, 2: string, 3: list<array{0: int, 1: string}>, 4: list<array{0: int, 1: string}>}>  $rows
     * @param  array<int, User>  $people
     * @param  array<int, EventOccurrence>  $events
     */
    private function seedEventComments(array $rows, array $people, array $events): void
    {
        $created = 0;
        $reactions = 0;
        foreach ($rows as $index => [$eventIndex, $person, $body, $replies, $reacts]) {
            $occurrence = $events[$eventIndex] ?? null;
            $user = $people[$person] ?? null;
            if ($occurrence === null || $user === null) {
                continue;
            }
            $at = CarbonImmutable::now()->subHours(30 - $index * 2);
            [$comment, $new] = $this->eventComment($occurrence->event_id, $user, $body, null, $at);
            $created += (int) $new;
            foreach ($replies as $position => [$replier, $text]) {
                if (isset($people[$replier])) {
                    $created += (int) $this->eventComment($occurrence->event_id, $people[$replier], $text, $comment, $at->addMinutes(35 + $position * 40))[1];
                }
            }
            foreach ($reacts as [$reactor, $type]) {
                if (isset($people[$reactor]) && ! EventCommentReaction::query()->where('event_comment_id', $comment->id)->where('user_id', $people[$reactor]->id)->exists()) {
                    (new EventCommentReaction)->forceFill(['event_comment_id' => $comment->id, 'user_id' => $people[$reactor]->id, 'type' => $type])->save();
                    $reactions++;
                }
            }
        }
        $ids = array_map(fn (User $u): int => $u->id, $people);
        $this->report['Commenti agli eventi (con risposte)'] = [$created, EventComment::query()->whereIn('user_id', $ids)->count()];
        $this->report['Reazioni ai commenti'] = [$reactions, EventCommentReaction::query()->whereIn('user_id', $ids)->count()];
    }

    /** @return array{0: EventComment, 1: bool} */
    private function eventComment(int $eventId, User $user, string $body, ?EventComment $parent, CarbonImmutable $at): array
    {
        $existing = EventComment::query()->where('event_id', $eventId)->where('user_id', $user->id)->where('body', $body)->first();
        if ($existing !== null) {
            return [$existing, false];
        }
        $comment = new EventComment;
        $comment->forceFill(['event_id' => $eventId, 'user_id' => $user->id, 'parent_id' => $parent?->id, 'reply_to_id' => $parent?->id,
            'body' => $body, 'status' => EventCommentStatus::Published, 'revision' => 1, 'created_at' => $at, 'updated_at' => $at])->save();

        return [$comment, true];
    }

    /**
     * Recensioni al locale dell'evento indicato, **in attesa di moderazione**:
     * mostrano la coda nel pannello senza pubblicare un voto inventato sulla
     * scheda di un'attività vera. Approvarle è una scelta della redazione.
     *
     * @param  list<array{0: int, 1: int, 2: int, 3: string}>  $rows
     * @param  array<int, User>  $people
     * @param  array<int, EventOccurrence>  $events
     */
    private function seedVenueReviews(array $rows, array $people, array $events): void
    {
        $created = 0;
        foreach ($rows as [$person, $eventIndex, $rating, $body]) {
            $user = $people[$person] ?? null;
            $venueId = isset($events[$eventIndex]) ? (int) $events[$eventIndex]->venue_id : null;
            if ($user === null || $venueId === null || CatalogReview::query()->where('reviewable_type', 'venue')->where('reviewable_id', $venueId)->where('user_id', $user->id)->exists()) {
                continue;
            }
            // Come `CatalogReviews::submit()`: la recensione non approvata e il voto nella tabella dei voti.
            DB::transaction(function () use ($venueId, $user, $rating, $body): void {
                $review = new CatalogReview;
                $review->forceFill(['reviewable_type' => 'venue', 'reviewable_id' => $venueId, 'user_id' => $user->id, 'review' => $body,
                    'department' => 'default', 'recommend' => false, 'approved' => false, 'status' => CatalogReviewStatus::Pending, 'revision' => 1])->save();
                (new CatalogRating)->forceFill(['review_id' => $review->id, 'key' => 'overall', 'value' => $rating])->save();
            });
            $created++;
        }
        $this->report['Recensioni dei locali (in attesa di moderazione)'] = [$created, CatalogReview::query()->whereIn('user_id', array_map(fn (User $u): int => $u->id, $people))->count()];
    }

    /**
     * I passaggi: offerte verso gli eventi della settimana, richieste, chat e
     * recensioni di viaggi conclusi (vedi `ShowcaseRides`).
     *
     * @param  array{rides: list<array{0: int, 1: int, 2: string, 3: string, 4: string, 5: int, 6: array<string, mixed>}>, ride_requests: list<array{0: int, 1: int, 2: int, 3: string, 4: string|null}>, ride_messages: list<array{0: int, 1: string, 2: string}>, ride_reviews: list<array{0: int, 1: int, 2: string, 3: int, 4: string}>}  $catalog
     * @param  array<int, User>  $people
     * @param  array<int, EventOccurrence>  $events
     */
    private function seedRides(City $city, array $catalog, array $people, array $events): void
    {
        if (! Schema::hasTable('ride_offers')) {
            $this->report['Passaggi (car pooling)'] = [0, 'non disponibile'];

            return;
        }
        $rides = app(ShowcaseRides::class);
        $past = $rides->pastDates($this->allInvestorOccurrences($city), count($catalog['ride_reviews']));
        foreach ($rides->seed($city, $catalog, $people, $events, $past) as $label => $row) {
            $this->report[$label] = $row;
        }
    }

    /**
     * Tutte le date del catalogo investitori, passate comprese: le recensioni
     * dei passaggi vogliono viaggi già fatti.
     *
     * @return Builder<EventOccurrence>
     */
    private function allInvestorOccurrences(City $city): Builder
    {
        return EventOccurrence::query()
            ->whereIn('event_id', Event::query()->where('city_id', $city->id)->where('status', 'published')->where('source_ref', 'like', InvestorDemoCommand::PREFIX.'%')->select('id'));
    }

    /**
     * Un avviso in app, come quello di CommunityNotification, scritto
     * direttamente nella tabella: nessun canale di consegna viene toccato.
     */
    private function notify(User $recipient, string $kind, string $url, ?int $actorId = null): int
    {
        $id = (string) Str::uuid();
        $recipient->notifications()->create([
            'id' => $id, 'type' => CommunityNotification::class,
            'data' => (new CommunityNotification($kind, $url, $actorId))->toArray($recipient), 'read_at' => null,
        ]);
        // L'avviso in app entra anche nella coda push (`UnifiedNotifications::archived`): si chiude
        // subito come consegnato, così `carpool:maintain` non lo prende mai in carico.
        if (Schema::hasTable('community_delivery_outbox')) {
            DB::table('community_delivery_outbox')->where('notification_id', $id)->whereNull('delivered_at')->update(['delivered_at' => now(), 'updated_at' => now()]);
        }

        return 1;
    }

    /** @param array{people: list<array{handle: string}>} $catalog */
    private function purge(City $city, array $catalog): int
    {
        $users = User::withTrashed()->whereIn('email', self::emails($catalog))->get();
        $events = Event::withTrashed()->where('city_id', $city->id)->where('is_demo', true)->where('source_ref', 'like', self::PREFIX.'%')->get();
        $ids = $users->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $eventIds = $events->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $others = $this->rowsOfOthers($ids, $eventIds);
        $this->info(sprintf('Da rimuovere: %d persone demo e %d eventi della vetrina.', $users->count(), $events->count()));
        $this->line($this->describeOthers($others));
        $live = Schema::hasTable('ride_offers') ? app(ShowcaseRides::class)->liveRowsOfOthers($ids, $eventIds) : [];
        if ($live !== []) {
            $this->warn('Sulle date della vetrina ci sono passaggi di persone vere ancora da fare:');
            foreach ($live as $line) {
                $this->line('  - '.$line);
            }
            if (! $this->option('force-real') && ! $this->option('dry-run')) {
                $this->error('Rimozione annullata: nessuna scrittura. Per cancellarli comunque serve --force-real.');

                return self::FAILURE;
            }
            $this->warn($this->option('force-real') ? 'Con --force-real verranno cancellati anche questi.' : 'Senza --force-real la rimozione verrebbe rifiutata.');
        }
        if ($this->option('dry-run')) {
            $this->line('Prova a vuoto: nessuna scrittura.');

            return self::SUCCESS;
        }

        return Cache::lock('showcase-demo:'.$city->id, 3600)->block(5, function () use ($users, $events, $city, $ids, $eventIds, $others): int {
            $morph = (new User)->getMorphClass();
            DB::transaction(function () use ($ids, $eventIds, $morph, $users): void {
                // Prima i passaggi: le loro chiavi esterne non vanno in cascata e fermerebbero persone ed eventi.
                if (Schema::hasTable('ride_offers')) {
                    app(ShowcaseRides::class)->purge($ids, $eventIds);
                }
                // Le chiavi polimorfiche non hanno cascata: le righe che puntano alle persone demo si tolgono a mano.
                $subjects = [
                    'community_post' => CommunityPost::query()->whereIn('user_id', $ids)->pluck('id')->all(),
                    'community_comment' => CommunityComment::query()->whereIn('user_id', $ids)->pluck('id')->all(),
                    'community_profile' => CommunityProfile::query()->whereIn('user_id', $ids)->pluck('id')->all(),
                    'event_comment' => EventComment::query()->whereIn('user_id', $ids)->pluck('id')->all(),
                    'user' => $ids,
                ];
                foreach ($subjects as $type => $subjectIds) {
                    Report::query()->where('reportable_type', $type)->whereIn('reportable_id', $subjectIds)->delete();
                }
                DB::table('followables')->where('followable_type', $morph)->whereIn('followable_id', $ids)->delete();
                DB::table('notifications')->where('notifiable_type', $morph)->whereIn('notifiable_id', $ids)->delete();
                // Il resto (profili, post, commenti, relazioni, blocchi, salvataggi, reazioni, recensioni dei locali) va in cascata.
                $users->each(fn (User $user) => $user->forceDelete());
            });
            // Uno per uno: così la libreria media cancella anche i file delle locandine.
            $events->each(fn (Event $event) => $event->forceDelete());
            ContentVersion::bump($city);
            $this->info(sprintf('Rimossi %d persone e %d eventi. %s', count($ids), $events->count(), $this->describeOthers($others, done: true)));

            return self::SUCCESS;
        });
    }

    /**
     * Le righe di **altri** utenti che la cancellazione porta via in cascata:
     * chi ha salvato una data demo, risposto a un commento demo, seguito una
     * persona demo o segnalato un suo contenuto. Si contano prima, perché dopo
     * non c'è più niente da contare.
     *
     * @param  list<int>  $userIds
     * @param  list<int>  $eventIds
     * @return array<string, int>
     */
    private function rowsOfOthers(array $userIds, array $eventIds): array
    {
        $occurrences = EventOccurrence::query()->whereIn('event_id', $eventIds)->select('id');
        $demoPosts = CommunityPost::query()->where(fn (Builder $q) => $q->whereIn('user_id', $userIds)->orWhereIn('occurrence_id', $occurrences))->select('id');
        $demoPostComments = CommunityComment::query()->whereIn('user_id', $userIds)->select('id');
        $demoEventComments = EventComment::query()->where(fn (Builder $q) => $q->whereIn('user_id', $userIds)->orWhereIn('event_id', $eventIds))->select('id');
        $subjects = [
            'community_post' => CommunityPost::query()->whereIn('user_id', $userIds)->select('id'),
            'community_comment' => CommunityComment::query()->whereIn('user_id', $userIds)->select('id'),
            'community_profile' => CommunityProfile::query()->whereIn('user_id', $userIds)->select('id'),
            'event_comment' => EventComment::query()->whereIn('user_id', $userIds)->select('id'),
        ];
        // Le segnalazioni anonime hanno `reporter_user_id` nullo: un NOT IN da solo le perderebbe.
        $reports = Report::query()->where(fn (Builder $q) => $q->whereNull('reporter_user_id')->orWhereNotIn('reporter_user_id', $userIds))->where(function (Builder $q) use ($subjects, $userIds): void {
            foreach ($subjects as $type => $subjectIds) {
                $q->orWhere(fn (Builder $r) => $r->where('reportable_type', $type)->whereIn('reportable_id', $subjectIds));
            }
            $q->orWhere(fn (Builder $r) => $r->where('reportable_type', 'user')->whereIn('reportable_id', $userIds));
        });

        return array_filter([
            'salvataggi' => SavedEvent::query()->whereNotIn('user_id', $userIds)->whereIn('occurrence_id', $occurrences)->count(),
            'post' => CommunityPost::query()->whereNotIn('user_id', $userIds)->whereIn('occurrence_id', $occurrences)->count(),
            'commenti ai post' => CommunityComment::query()->whereNotIn('user_id', $userIds)
                ->where(fn (Builder $q) => $q->whereIn('community_post_id', $demoPosts)->orWhereIn('parent_id', $demoPostComments))->count(),
            'commenti agli eventi' => EventComment::query()->whereNotIn('user_id', $userIds)
                ->where(fn (Builder $q) => $q->whereIn('event_id', $eventIds)->orWhereIn('parent_id', $demoEventComments))->count(),
            'reazioni' => EventCommentReaction::query()->whereNotIn('user_id', $userIds)->whereIn('event_comment_id', $demoEventComments)->count(),
            'relazioni' => DB::table('followables')->where('followable_type', (new User)->getMorphClass())->whereIn('followable_id', $userIds)->whereNotIn('user_id', $userIds)->count(),
            'segnalazioni' => $reports->count(),
            ...(Schema::hasTable('ride_offers') ? app(ShowcaseRides::class)->others($userIds, $eventIds) : []),
        ]);
    }

    /** @param array<string, int> $others */
    private function describeOthers(array $others, bool $done = false): string
    {
        if ($others === []) {
            return $done ? 'Nessuna riga di altri utenti è stata toccata.' : 'Nessuna riga di altri utenti verrà toccata.';
        }
        $list = implode(', ', array_map(fn (string $label, int $count): string => "{$count} {$label}", array_keys($others), $others));

        return ($done ? 'Con loro sono sparite righe di altri utenti: ' : 'Con loro spariranno righe di altri utenti: ').$list.'.';
    }
}
