<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Showcase\ShowcaseRides;
use App\Enums\BookingStatus;
use App\Enums\CommunityStatus;
use App\Enums\EventCommentReactionType;
use App\Enums\EventCommentStatus;
use App\Enums\PostIntent;
use App\Enums\ProfileVisibility;
use App\Enums\SavedVisibility;
use App\Enums\SponsorshipPlacement;
use App\Enums\SponsorshipStatus;
use App\Enums\VerificationStatus;
use App\Models\Booking;
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
use App\Models\EventPoll;
use App\Models\EventShareLink;
use App\Models\Follow;
use App\Models\RideOffer;
use App\Models\SavedEvent;
use App\Models\Setting;
use App\Models\Sponsorship;
use App\Models\User;
use App\Models\Venue;
use App\Services\Analytics\EventShares;
use App\Services\Community\EventPolls;
use App\Services\Community\WhatsappVerification;
use App\Services\Ticketing\TicketingService;
use App\Support\ContentVersion;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as Events;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Quindici giorni di cartellone dimostrativo, completi di tutto ciò che la
 * piattaforma sa mostrare.
 *
 * Due fonti di date, entrambe dal giorno indicato (di norma oggi) ai
 * quattordici successivi:
 *
 * - **eventi nuovi** dal catalogo `quindicina-demo.php`, ognuno con la sua
 *   fotografia accreditata e, quando è una rassegna, con più date;
 * - **nuove date per eventi demo già passati** (catalogo investitori, vetrina,
 *   presentazione): lo stesso evento, con la sua scheda e la sua storia,
 *   torna in cartellone in un giorno nuovo. La locandina di categoria, uguale
 *   per decine di eventi, viene sostituita con una della stessa categoria
 *   scelta da una piccola raccolta, così due schede della stessa sera non si
 *   somigliano.
 *
 * Attorno alle date: persone (quelle della vetrina più dodici nuove), «ci
 * vado», salvataggi, post con commenti, commenti alle schede con risposte e
 * reazioni, informazioni pratiche e costi, visite e condivisioni, sondaggi
 * fra amici sulle rassegne, una lista d'attesa con posto promosso e staff
 * agli ingressi, passaggi con richieste in ogni stato e chat, recensioni di
 * viaggi conclusi.
 *
 * **Stesso modello di sicurezza di `demo:showcase`**: fuori da local e
 * testing serve `--allow-production`; un lock impedisce due esecuzioni
 * parallele; una seconda esecuzione non duplica niente e completa ciò che
 * manca; nessuna riga passa dalle Action che notificano; eventi `is_demo`,
 * che `NotificationScheduler`, riepiloghi e social ignorano e che restano
 * «non confermati dal locale»; persone con indirizzo `.invalid`, che
 * `User::canReceiveNotifications()` esclude da ogni invio.
 *
 * Le date aggiunte agli eventi esistenti non hanno una colonna che le
 * distingua: i loro identificativi stanno nell'impostazione
 * `demo.quindicina.date_riusate`, ed è da lì che `--purge` le ritrova senza
 * toccare lo storico di quegli eventi.
 */
final class FortnightDemoCommand extends Command
{
    public const PREFIX = 'quindicina-demo-v1:';

    /** L'impostazione con le date aggiunte agli eventi già esistenti. */
    public const SETTING = 'demo.quindicina.date_riusate';

    /** Il committente delle campagne dimostrative: mai il nome di un locale vero. */
    private const DEMO_ADVERTISER = 'Campagna dimostrativa';

    /** L'inizio della nota dei sondaggi: è la chiave con cui `--purge` li ritrova. */
    private const POLL_NOTE = 'Quindicina inCittà — ';

    private const DAYS = 15;

    /** @see ShowcaseDemoCommand::QUIET_PREFERENCES */
    private const QUIET_PREFERENCES = ['reminders' => false, 'sold_out' => false, 'venue_digest' => false, 'daily_digest' => false, 'comments' => false, 'delivery' => 'database'];

    /** Le fasce orarie in cui le date riusate tornano, se l'orario originale cade in un giorno già passato. */
    private const FALLBACK_TIMES = ['18:30', '19:00', '20:30', '21:00', '21:30'];

    protected $signature = 'demo:quindicina {city=padova} {--dal= : Primo giorno (AAAA-MM-GG), di norma oggi} {--riusati=60 : Quanti eventi passati tornano con una data nuova} {--dry-run} {--allow-production} {--purge : Toglie tutto ciò che questo comando ha aggiunto}';

    protected $description = 'Riempie i prossimi quindici giorni: eventi nuovi con foto, nuove date per eventi passati, community, passaggi, sondaggi e lista d’attesa';

    /** @var array<string, array{0: int|string, 1: int|string}> */
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

        $reuse = filter_var($this->option('riusati'), FILTER_VALIDATE_INT);
        if ($reuse === false || $reuse < 0 || $reuse > 200) {
            $this->error('--riusati accetta da 0 a 200.');

            return self::FAILURE;
        }

        $categories = Category::query()->get()->keyBy('slug');
        $credits = self::credits();
        $venues = Venue::query()->approved()->where('city_id', $city->id)->orderBy('id')->get();
        $this->validate($catalog, $categories, $credits, $venues);
        $start = $this->firstDay($city);

        $newDates = array_sum(array_map(fn (array $row): int => 1 + count($row['repeat'] ?? []), $catalog['events']));
        $this->info(sprintf('Quindicina dal %s al %s: %d eventi nuovi (%d date), fino a %d eventi passati con una data nuova, %d persone.',
            $start->format('d/m/Y'), $start->addDays(self::DAYS - 1)->format('d/m/Y'), count($catalog['events']), $newDates, $reuse,
            count(ShowcaseDemoCommand::catalog()['people']) + count($catalog['people'])));

        if ($this->option('dry-run')) {
            $this->table(['Elemento', 'Previsti'], [
                ['Eventi nuovi con fotografia', count($catalog['events'])],
                ['Date degli eventi nuovi (quelle già passate si saltano)', $newDates],
                ['Eventi passati disponibili per una data nuova', $this->reusableEvents($city, $start)->count().' (ne servono '.$reuse.')'],
                ['Persone (vetrina + nuove)', count(ShowcaseDemoCommand::catalog()['people']).' + '.count($catalog['people'])],
            ]);
            $this->line('Prova a vuoto: nessuna scrittura.');

            return self::SUCCESS;
        }

        if ((string) config('community.phone_hash_key') === '') {
            $this->error('WHATSAPP_PHONE_HASH_KEY non è configurata: senza impronta le persone non risultano verificate.');

            return self::FAILURE;
        }

        return Cache::lock('fortnight-demo:'.$city->id, 3600)->block(5, function () use ($city, $catalog, $categories, $credits, $venues, $start, $reuse): int {
            $this->silenceDemoPeople();
            // Centinaia di locandine nuove non devono accodare altrettante immagini social.
            $previousOg = config('media.og.enabled');
            config(['media.og.enabled' => false]);

            try {
                $people = $this->seedPeople($city, $catalog['people']);
                $fresh = $this->seedNewEvents($city, $catalog['events'], $categories, $credits, $venues, $start);
                $reused = $this->seedReusedDates($city, $start, $reuse, $credits);
                $this->keepDemoEventsUnverified($city);

                $dates = (new Collection([...$fresh, ...$reused]))->unique('id')
                    ->filter(fn (EventOccurrence $date): bool => $date->starts_at->isFuture())
                    ->sortBy(fn (EventOccurrence $date): int => $date->starts_at->getTimestamp())->values();
                $dates->load(['event.venue', 'event.category', 'venue']);

                $this->seedFollows($people, $dates);
                $this->seedAttendance($dates, $people);
                $posts = $this->seedPosts($dates, $people, $catalog['posts']);
                $this->seedPostComments($posts, $people, $catalog['post_comments']);
                $this->seedEventComments($dates, $people, $catalog['comments']);
                $this->seedPractical($dates);
                $this->seedViews($dates);
                $this->seedShares($dates);
                $this->seedEventViews($dates);
                $this->seedSponsorshipStats($dates);
                $this->seedPolls($dates, $people);
                $this->seedTicketing($dates, $people);
                $this->seedRides($city, $catalog, $people, $dates);
            } finally {
                config(['media.og.enabled' => $previousOg]);
            }

            ContentVersion::bump($city);

            $this->table(['Elemento', 'Creati ora', 'Totale'], array_map(
                fn (string $label, array $row): array => [$label, $row[0], $row[1]],
                array_keys($this->report), $this->report,
            ));
            $this->info('Quindicina pronta. Per toglierla: php artisan demo:quindicina '.$city->slug.' --purge');

            return self::SUCCESS;
        });
    }

    /**
     * Nessun avviso alle persone demo, su nessun canale, finché il comando gira.
     *
     * La lista d'attesa passa da `TicketingService`, che a ogni prenotazione
     * avvisa chi prenota con `BookingChanged` per mail e in app — e quella
     * notifica non guarda `canReceiveNotifications()`. Un indirizzo `.invalid`
     * non arriva da nessuna parte, ma la mail partirebbe lo stesso verso il
     * server di posta. Rispondere `false` a `NotificationSending` è il modo in
     * cui Laravel annulla un invio prima di qualunque canale.
     */
    private function silenceDemoPeople(): void
    {
        Events::listen(NotificationSending::class, function (NotificationSending $event): ?bool {
            $notifiable = $event->notifiable;

            return $notifiable instanceof User && str_ends_with((string) $notifiable->email, '@'.ShowcaseDemoCommand::EMAIL_DOMAIN) ? false : null;
        });
    }

    /**
     * @return array{events: list<array<string, mixed>>, people: list<array{handle: string, name: string, bio: string, visibility: string, featured: bool}>, comments: array<string, list<array{0: string, 1: list<string>}>>, posts: list<array{0: string, 1: string}>, post_comments: list<string>, ride_zones: list<array{0: string, 1: list<string>}>, ride_notes: list<string>, ride_request_notes: list<string|null>, ride_messages: list<array{0: string, 1: string}>, ride_reviews: list<array{0: int, 1: string}>}
     */
    public static function catalog(): array
    {
        /** @var array{events: list<array<string, mixed>>, people: list<array{handle: string, name: string, bio: string, visibility: string, featured: bool}>, comments: array<string, list<array{0: string, 1: list<string>}>>, posts: list<array{0: string, 1: string}>, post_comments: list<string>, ride_zones: list<array{0: string, 1: list<string>}>, ride_notes: list<string>, ride_request_notes: list<string|null>, ride_messages: list<array{0: string, 1: string}>, ride_reviews: list<array{0: int, 1: string}>} $catalog */
        $catalog = require database_path('seeders/data/quindicina-demo.php');

        return $catalog;
    }

    /** @return array<string, array<string, mixed>> */
    public static function credits(): array
    {
        /** @var array<string, array<string, mixed>> $credits */
        $credits = json_decode((string) file_get_contents(database_path('seeders/quindicina-media/credits.json')), true, 512, JSON_THROW_ON_ERROR);

        return $credits;
    }

    /**
     * Gli indirizzi delle persone **nuove** di questo comando: quelle della
     * vetrina appartengono a `demo:showcase` e si tolgono con il suo `--purge`.
     *
     * @param  array{people: list<array{handle: string}>}  $catalog
     * @return list<string>
     */
    public static function emails(array $catalog): array
    {
        return array_map(fn (array $person): string => $person['handle'].'@'.ShowcaseDemoCommand::EMAIL_DOMAIN, $catalog['people']);
    }

    /** Un numero nell'intervallo +39 000… non assegnabile, distinto da quelli della vetrina. */
    public static function phone(int $index): string
    {
        return '+39000000'.(1001 + $index);
    }

    /**
     * Un indice deterministico: lo stesso testo dà sempre la stessa scelta,
     * così una seconda esecuzione ritrova gli stessi abbinamenti.
     */
    private static function pick(string $seed, int $count): int
    {
        return $count <= 0 ? 0 : crc32($seed) % $count;
    }

    private function firstDay(City $city): CarbonImmutable
    {
        $option = $this->option('dal');
        if (! is_string($option) || $option === '') {
            return CarbonImmutable::now($city->timezone)->startOfDay();
        }
        try {
            $day = CarbonImmutable::createFromFormat('Y-m-d', $option, $city->timezone)?->startOfDay();
        } catch (InvalidFormatException) {
            $day = null;
        }
        if ($day === null || $day->format('Y-m-d') !== $option) {
            throw new \RuntimeException('--dal vuole una data nel formato AAAA-MM-GG.');
        }

        return $day;
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
        if (count(array_unique($titles)) !== count($titles)) {
            throw new \RuntimeException('Il catalogo ha titoli ripetuti.');
        }
        $handles = array_column($catalog['people'], 'handle');
        $showcase = array_column(ShowcaseDemoCommand::catalog()['people'], 'handle');
        if (count(array_unique([...$handles, ...$showcase])) !== count($handles) + count($showcase) || preg_grep('/^[a-z0-9_]{3,40}$/', $handles, PREG_GREP_INVERT) !== []) {
            throw new \RuntimeException('Gli handle delle persone devono essere unici, anche rispetto alla vetrina, e validi.');
        }
        if ($venues->isEmpty()) {
            throw new \RuntimeException('Nessun locale approvato nella città.');
        }
        foreach ($catalog['events'] as $row) {
            if (! isset($categories[$row['category']])) {
                throw new \RuntimeException('Categoria sconosciuta: '.$row['title']);
            }
            if (! isset($credits[$row['photo']]) || ! is_file(self::mediaPath($credits[$row['photo']]))) {
                throw new \RuntimeException('Fotografia mancante: '.$row['title']);
            }
            foreach ([$row['day'], ...($row['repeat'] ?? [])] as $day) {
                if (! is_int($day) || $day < 0 || $day >= self::DAYS) {
                    throw new \RuntimeException('Giorno fuori dalla quindicina: '.$row['title']);
                }
            }
        }
    }

    /** @param array<string, mixed> $credit */
    private static function mediaPath(array $credit): string
    {
        return database_path('seeders/quindicina-media/'.$credit['file']);
    }

    /**
     * Le persone della vetrina (create qui se mancano, con gli stessi valori
     * di `demo:showcase`) e le dodici nuove. L'elenco è indicizzato: prima la
     * vetrina, poi le nuove.
     *
     * @param  list<array{handle: string, name: string, bio: string, visibility: string, featured: bool}>  $extra
     * @return list<User>
     */
    private function seedPeople(City $city, array $extra): array
    {
        $rows = [];
        foreach (ShowcaseDemoCommand::catalog()['people'] as $index => $row) {
            $rows[] = [$row, ShowcaseDemoCommand::phone($index)];
        }
        foreach ($extra as $index => $row) {
            $rows[] = [$row, self::phone($index)];
        }

        $fingerprints = app(WhatsappVerification::class);
        $people = [];
        $created = 0;
        foreach ($rows as $index => [$row, $phone]) {
            $email = $row['handle'].'@'.ShowcaseDemoCommand::EMAIL_DOMAIN;
            $user = User::withTrashed()->where('email', $email)->first();
            if ($user?->trashed()) {
                continue; // Account cancellato dall'amministrazione: non si ricrea.
            }
            if (CommunityProfile::query()->where('handle', $row['handle'])->when($user !== null, fn ($q) => $q->where('user_id', '!=', $user->id))->exists()) {
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
                $verifiedAt = CarbonImmutable::now()->subDays(25 - $index % 20);
                $user->forceFill([
                    'email_verified_at' => $verifiedAt, 'whatsapp_phone' => $phone, 'whatsapp_phone_hash' => $fingerprints->fingerprint($phone),
                    'whatsapp_verified_at' => $verifiedAt, 'whatsapp_prompted_at' => $verifiedAt, 'last_active_at' => now()->subHours($index % 30),
                ])->save();
                $created++;
            }
            if (array_intersect_key($user->notification_preferences ?? [], self::QUIET_PREFERENCES) !== self::QUIET_PREFERENCES) {
                $user->forceFill(['notification_preferences' => [...($user->notification_preferences ?? []), ...self::QUIET_PREFERENCES]])->save();
            }
            if (! $user->communityProfile()->exists()) {
                $profile = new CommunityProfile(['handle' => $row['handle'], 'display_name' => $row['name'], 'bio' => $row['bio'].' Profilo dimostrativo.',
                    'city_id' => $city->id, 'visibility' => ProfileVisibility::from($row['visibility']), 'indexable' => false]);
                $profile->featured = $row['featured'];
                $user->communityProfile()->save($profile);
            }
            $people[] = $user;
        }
        $this->report['Persone verificate WhatsApp'] = [$created, count($people)];

        return $people;
    }

    /**
     * Gli eventi nuovi del catalogo, con le loro date nella quindicina.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  \Illuminate\Support\Collection<string, Category>  $categories
     * @param  array<string, array<string, mixed>>  $credits
     * @param  Collection<int, Venue>  $venues
     * @return list<EventOccurrence>
     */
    private function seedNewEvents(City $city, array $rows, \Illuminate\Support\Collection $categories, array $credits, Collection $venues, CarbonImmutable $start): array
    {
        $features = EventFeature::query()->pluck('id', 'slug')->map(fn ($id): int => (int) $id)->all();
        $createdEvents = 0;
        $createdDates = 0;
        $dates = [];
        foreach ($rows as $index => $row) {
            $ref = self::PREFIX.str_pad((string) $index, 3, '0', STR_PAD_LEFT);
            $event = Event::withTrashed()->where('city_id', $city->id)->where('source_ref', $ref)->first();
            if ($event?->trashed()) {
                continue; // Una cancellazione della redazione si rispetta.
            }
            $matching = $venues->filter(fn (Venue $venue): bool => in_array($venue->type->value, $row['venue_types'], true))->values();
            $pool = $matching->isEmpty() ? $venues : $matching;
            $venue = $event === null ? $pool[self::pick($row['title'], $pool->count())] : ($event->venue ?? $pool[0]);
            [$hour, $minute] = array_map('intval', explode(':', $row['time']));

            if ($event === null) {
                $paid = $row['price'] > 0;
                $family = (bool) ($row['family'] ?? false);
                $event = Event::create([
                    'city_id' => $city->id, 'venue_id' => $venue->id, 'category_id' => $categories[$row['category']]->id,
                    'title' => $row['title'],
                    'description' => $row['description']."\n\nEvento dimostrativo: appuntamento fittizio per presentare inCittà, non confermato dal locale. Non è possibile prenotare.",
                    'short_description' => Str::limit($row['description'], 450),
                    'source' => 'manual', 'source_ref' => $ref, 'status' => 'published', 'published_at' => now(),
                    'verification_status' => 'unverified', 'is_demo' => true,
                    'content_details' => [
                        'feature_ids' => array_values(array_intersect_key($features, array_flip($row['features']))),
                        'accessibility' => $row['accessible'] ? 'yes' : 'no',
                        'accessibility_notes' => $row['accessible'] ? 'Ingresso senza gradini e bagno accessibile. Assistenza disponibile all’ingresso.' : 'Il locale ha alcuni gradini all’ingresso: chiedere assistenza allo staff.',
                        'age_groups' => $family ? ['all', '3-5', '6-10'] : ['18-plus'],
                        'stroller' => $family ? 'yes' : 'no',
                    ],
                    'is_outdoor' => in_array('allaperto', $row['features'], true),
                    'price_type' => $paid ? 'ticket' : 'free', 'price_min' => $row['price'],
                    'price_notes' => $paid ? 'Pagamento all’ingresso.' : 'Ingresso libero.',
                    'currency' => 'EUR', 'booking_required' => in_array('prenotazione-obbligatoria', $row['features'], true), 'language' => 'it',
                ]);
                $createdEvents++;
            }
            if (! $event->hasMedia('poster')) {
                $credit = $credits[$row['photo']];
                $event->addMedia(self::mediaPath($credit))->preservingOriginal()
                    ->withCustomProperties(['illustrative' => true, 'credit' => $credit])->toMediaCollection('poster');
            }

            foreach (array_unique([$row['day'], ...($row['repeat'] ?? [])]) as $day) {
                $at = $start->addDays($day)->setTime($hour, $minute);
                if ($at->isPast()) {
                    continue;
                }
                $date = EventOccurrence::query()->where('event_id', $event->id)->where('starts_at', $at->utc())->first();
                if ($date === null) {
                    $date = $event->occurrences()->create(['venue_id' => $venue->id, 'starts_at' => $at->utc(),
                        'ends_at' => $at->addMinutes($this->duration($row['category']))->utc(), 'status' => 'scheduled', 'is_all_day' => false, 'booking_enabled' => false]);
                    $createdDates++;
                }
                $dates[] = $date;
            }
        }
        $this->report['Eventi nuovi con fotografia'] = [$createdEvents, Event::query()->where('city_id', $city->id)->where('source_ref', 'like', self::PREFIX.'%')->count()];
        $this->report['Date degli eventi nuovi'] = [$createdDates, count($dates)];

        return $dates;
    }

    private function duration(string $category): int
    {
        return match ($category) {
            'mercatini', 'arte-e-mostre' => 300,
            'dj-set-nightlife' => 240,
            'food-e-sagre', 'sport', 'corsi-e-workshop' => 180,
            default => 120,
        };
    }

    /**
     * Gli eventi demo che possono tornare in cartellone: pubblicati, con la
     * locandina, e senza date dal primo giorno della quindicina in poi — un
     * evento che ha già una data futura non ha bisogno di un'altra.
     *
     * @return Collection<int, Event>
     */
    private function reusableEvents(City $city, CarbonImmutable $start): Collection
    {
        return Event::query()->where('city_id', $city->id)->where('is_demo', true)->where('status', 'published')
            ->where(fn ($q) => $q->whereNull('source_ref')->orWhere('source_ref', 'not like', self::PREFIX.'%'))
            ->whereNotNull('venue_id')->whereHas('media', fn ($q) => $q->where('collection_name', 'poster'))
            ->whereDoesntHave('occurrences', fn ($q) => $q->where('starts_at', '>=', $start->utc()))
            ->whereHas('occurrences')->with(['category', 'media'])->orderBy('id')->get()
            ->unique(fn (Event $event): string => Str::lower(trim($event->title)))->values();
    }

    /**
     * Le date nuove per eventi già passati.
     *
     * La scelta gira sulle categorie, così la quindicina non si riempie di
     * dieci concerti uguali; il giorno scorre sui quindici, e l'ora è quella
     * originale dell'evento — una rassegna delle 21 resta alle 21. Le date già
     * create da un'esecuzione precedente si ritrovano nell'impostazione e non
     * se ne aggiungono altre agli stessi eventi.
     *
     * @param  array<string, array<string, mixed>>  $credits
     * @return list<EventOccurrence>
     */
    private function seedReusedDates(City $city, CarbonImmutable $start, int $wanted, array $credits): array
    {
        $registered = $this->registeredReusedDates();
        $existing = EventOccurrence::query()->whereIn('id', $registered)->where('starts_at', '>=', $start->utc())->get();
        $dates = $existing->all();
        $missing = max(0, $wanted - $existing->pluck('event_id')->unique()->count());
        $created = 0;
        $posters = 0;

        if ($missing > 0) {
            $byCategory = $this->reusableEvents($city, $start)->groupBy(fn (Event $event): string => (string) $event->category?->slug);
            $chosen = [];
            for ($round = 0; count($chosen) < $missing && $byCategory->contains(fn ($events): bool => $events->count() > $round); $round++) {
                foreach ($byCategory as $events) {
                    if (count($chosen) < $missing && isset($events[$round])) {
                        $chosen[] = $events[$round];
                    }
                }
            }

            foreach ($chosen as $position => $event) {
                $first = $event->occurrences()->orderByDesc('starts_at')->first();
                if ($first === null) {
                    continue;
                }
                $original = CarbonImmutable::instance($first->starts_at)->timezone($city->timezone);
                $day = ($position * 7 + 3) % self::DAYS;
                $at = $start->addDays($day)->setTime((int) $original->format('H'), (int) $original->format('i'));
                if ($at->isPast()) {
                    [$hour, $minute] = array_map('intval', explode(':', self::FALLBACK_TIMES[$position % count(self::FALLBACK_TIMES)]));
                    $at = $start->addDays(1 + $position % (self::DAYS - 1))->setTime($hour, $minute);
                }
                $length = $first->ends_at !== null ? max(60, (int) $first->starts_at->diffInMinutes($first->ends_at)) : 120;
                $date = $event->occurrences()->create(['venue_id' => $first->venue_id ?? $event->venue_id, 'starts_at' => $at->utc(),
                    'ends_at' => $at->addMinutes(min($length, 360))->utc(), 'is_all_day' => false, 'status' => 'scheduled', 'booking_enabled' => false]);
                $registered[] = (int) $date->id;
                $dates[] = $date;
                $created++;
                $posters += $this->refreshPoster($event, $credits, $position);
            }
            $this->registerReusedDates($registered);
        }

        $this->report['Eventi passati tornati con una data nuova'] = [$created, count($dates)];
        $this->report['Locandine di categoria sostituite'] = [$posters, $posters];

        return $dates;
    }

    /**
     * Una locandina della raccolta al posto di quella di categoria, condivisa
     * da decine di eventi. Le locandine caricate a mano (senza la proprietà
     * `illustrative`) non si toccano.
     *
     * @param  array<string, array<string, mixed>>  $credits
     */
    private function refreshPoster(Event $event, array $credits, int $position): int
    {
        $poster = $event->getFirstMedia('poster');
        if ($poster === null || ! $poster->getCustomProperty('illustrative') || str_starts_with((string) $poster->file_name, 'pool-')) {
            return 0;
        }
        $slug = (string) $event->category?->slug;
        $pool = array_values(array_filter(array_keys($credits), fn (string $key): bool => str_starts_with($key, 'pool-'.$slug.'-')));
        if ($pool === []) {
            return 0;
        }
        $credit = $credits[$pool[$position % count($pool)]];
        $event->addMedia(self::mediaPath($credit))->preservingOriginal()
            ->withCustomProperties(['illustrative' => true, 'credit' => $credit])->toMediaCollection('poster');

        return 1;
    }

    /** @return list<int> */
    private function registeredReusedDates(): array
    {
        $value = Setting::query()->where('key', self::SETTING)->value('value');
        $ids = is_string($value) ? json_decode($value, true) : [];

        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }

    /** @param list<int> $ids */
    private function registerReusedDates(array $ids): void
    {
        Setting::query()->updateOrCreate(['key' => self::SETTING], ['value' => json_encode(array_values(array_unique($ids))), 'type' => 'json', 'group' => 'demo',
            'description' => 'Date aggiunte da demo:quindicina a eventi demo esistenti; demo:quindicina --purge le toglie.']);
    }

    /** @see ShowcaseDemoCommand::keepDemoEventsUnverified() */
    private function keepDemoEventsUnverified(City $city): void
    {
        Event::query()->where('city_id', $city->id)->where('is_demo', true)
            ->where('verification_status', '!=', VerificationStatus::Unverified->value)
            ->update(['verification_status' => VerificationStatus::Unverified->value]);
    }

    /**
     * Relazioni fra le persone nuove e quelle della vetrina, e i locali delle
     * date seguiti senza avvisi.
     *
     * @param  list<User>  $people
     * @param  Collection<int, EventOccurrence>  $dates
     */
    private function seedFollows(array $people, Collection $dates): void
    {
        $morph = (new User)->getMorphClass();
        $count = count($people);
        $created = 0;
        $venues = $dates->map(fn (EventOccurrence $date): ?int => $date->venue_id ?? $date->event?->venue_id)->filter()->unique()->values();
        foreach ($people as $index => $user) {
            foreach ([2, 7, 11] as $offset) {
                $target = $people[($index + $offset) % $count];
                if ($target->id === $user->id || DB::table('followables')->where('user_id', $user->id)->where('followable_type', $morph)->where('followable_id', $target->id)->exists()) {
                    continue;
                }
                $at = now()->subDays(($index + $offset) % 20 + 1);
                DB::table('followables')->insert(['user_id' => $user->id, 'followable_type' => $morph, 'followable_id' => $target->id, 'accepted_at' => $at, 'created_at' => $at, 'updated_at' => $at]);
                $created++;
            }
            foreach ([0, 1] as $k) {
                if ($venues->isNotEmpty()) {
                    $created += (int) Follow::query()->firstOrCreate(['user_id' => $user->id, 'followable_type' => 'venue',
                        'followable_id' => $venues[($index * 2 + $k) % $venues->count()]], ['notify' => false])->wasRecentlyCreated;
                }
            }
        }
        $this->report['Relazioni e locali seguiti'] = [$created, $created];
    }

    /**
     * «Ci vado» e salvataggi.
     *
     * Non tutte le date: una su quattro resta senza nessuno, perché un
     * cartellone vero ha anche le serate che nessuno ha ancora scoperto. Sulle
     * altre da tre a nove persone, di più nel fine settimana.
     *
     * @param  Collection<int, EventOccurrence>  $dates
     * @param  list<User>  $people
     */
    private function seedAttendance(Collection $dates, array $people): void
    {
        $attendances = 0;
        $saves = 0;
        $count = count($people);
        foreach ($dates as $index => $date) {
            if ($index % 4 === 3) {
                continue;
            }
            $weekend = in_array((int) $date->starts_at->timezone(config('app.timezone'))->dayOfWeekIso, [5, 6, 7], true);
            $howMany = 3 + self::pick('ci-vado:'.$date->id, $weekend ? 7 : 4);
            $first = self::pick('gruppo:'.$date->id, $count);
            for ($k = 0; $k < $howMany; $k++) {
                $person = $people[($first + $k * 3) % $count];
                $saves += (int) SavedEvent::query()->firstOrCreate(['user_id' => $person->id, 'occurrence_id' => $date->id])->wasRecentlyCreated;
                $at = now()->subHours(2 + self::pick('ora:'.$date->id.':'.$person->id, 120));
                $attendances += DB::table('community_attendances')->insertOrIgnore(['user_id' => $person->id, 'occurrence_id' => $date->id, 'created_at' => $at, 'updated_at' => $at]);
            }
            // Chi salva senza dire «ci vado»: la data interessa, la decisione no.
            $saver = $people[($first + 1) % $count];
            $saves += (int) SavedEvent::query()->firstOrCreate(['user_id' => $saver->id, 'occurrence_id' => $date->id])->wasRecentlyCreated;
        }
        $ids = array_map(fn (User $u): int => $u->id, $people);
        $this->report['«Ci vado»'] = [$attendances, DB::table('community_attendances')->whereIn('user_id', $ids)->whereIn('occurrence_id', $dates->modelKeys())->count()];
        $this->report['Salvataggi'] = [$saves, SavedEvent::query()->whereIn('user_id', $ids)->whereIn('occurrence_id', $dates->modelKeys())->count()];
    }

    /**
     * Post pubblici: un post è un salvataggio reso pubblico con un testo, e
     * «parteciperò» porta con sé anche la riga di partecipazione.
     *
     * @param  Collection<int, EventOccurrence>  $dates
     * @param  list<User>  $people
     * @param  list<array{0: string, 1: string}>  $texts
     * @return list<CommunityPost>
     */
    private function seedPosts(Collection $dates, array $people, array $texts): array
    {
        $posts = [];
        $created = 0;
        $count = count($people);
        foreach ($dates->values() as $index => $date) {
            if ($index % 3 !== 0) {
                continue;
            }
            [$intent, $body] = $texts[self::pick('post:'.$date->id, count($texts))];
            $user = $people[self::pick('autore:'.$date->id, $count)];
            $at = CarbonImmutable::now()->subHours(1 + self::pick('pubblicato:'.$date->id, 96));
            $saved = SavedEvent::query()->firstOrCreate(['user_id' => $user->id, 'occurrence_id' => $date->id]);
            $saved->forceFill(['visibility' => SavedVisibility::Public])->save();
            if ($intent === PostIntent::Attend->value) {
                DB::table('community_attendances')->insertOrIgnore(['user_id' => $user->id, 'occurrence_id' => $date->id, 'created_at' => $at, 'updated_at' => $at]);
            }
            $post = CommunityPost::query()->firstOrNew(['saved_event_id' => $saved->id]);
            if (! $post->exists) {
                $post->forceFill(['user_id' => $user->id, 'occurrence_id' => $date->id, 'body' => $body, 'intent' => PostIntent::from($intent),
                    'status' => CommunityStatus::Published, 'published_at' => $at, 'created_at' => $at, 'updated_at' => $at])->save();
                $created++;
            }
            $posts[] = $post;
        }
        $this->report['Post pubblici'] = [$created, count($posts)];

        return $posts;
    }

    /**
     * @param  list<CommunityPost>  $posts
     * @param  list<User>  $people
     * @param  list<string>  $texts
     */
    private function seedPostComments(array $posts, array $people, array $texts): void
    {
        $created = 0;
        $count = count($people);
        foreach ($posts as $index => $post) {
            for ($k = 0; $k < 1 + $index % 3; $k++) {
                $user = $people[(self::pick('commentatore:'.$post->id, $count) + $k * 5) % $count];
                if ($user->id === $post->user_id) {
                    continue;
                }
                $body = $texts[self::pick('commento:'.$post->id.':'.$k, count($texts))];
                if (CommunityComment::query()->where('community_post_id', $post->id)->where('user_id', $user->id)->where('body', $body)->exists()) {
                    continue;
                }
                $at = CarbonImmutable::parse($post->published_at)->addMinutes(15 + $k * 40);
                CommunityComment::query()->create(['community_post_id' => $post->id, 'user_id' => $user->id, 'body' => $body,
                    'parent_id' => null, 'status' => CommunityStatus::Published, 'created_at' => $at, 'updated_at' => $at]);
                $created++;
            }
        }
        $this->report['Commenti ai post'] = [$created, CommunityComment::query()->whereIn('community_post_id', array_map(fn (CommunityPost $p): int => $p->id, $posts))->count()];
    }

    /**
     * Le conversazioni sotto le schede: una domanda della categoria, con le
     * sue risposte e qualche reazione. Su poco più della metà degli eventi.
     *
     * @param  Collection<int, EventOccurrence>  $dates
     * @param  list<User>  $people
     * @param  array<string, list<array{0: string, 1: list<string>}>>  $pool
     */
    private function seedEventComments(Collection $dates, array $people, array $pool): void
    {
        $created = 0;
        $reactions = 0;
        $count = count($people);
        $types = EventCommentReactionType::cases();
        foreach ($dates->pluck('event')->filter()->unique('id')->values() as $index => $event) {
            if ($index % 5 >= 3) {
                continue;
            }
            $threads = $pool[(string) $event->category?->slug] ?? $pool['altro'];
            [$question, $answers] = $threads[self::pick('domanda:'.$event->id, count($threads))];
            $author = $people[self::pick('chiede:'.$event->id, $count)];
            $at = CarbonImmutable::now()->subHours(3 + self::pick('quando:'.$event->id, 60));
            [$comment, $new] = $this->eventComment($event, $author, $question, null, $at);
            $created += (int) $new;
            foreach ($answers as $position => $answer) {
                $replier = $people[(self::pick('risponde:'.$event->id, $count) + $position * 4 + 1) % $count];
                if ($replier->id !== $author->id) {
                    $created += (int) $this->eventComment($event, $replier, $answer, $comment, $at->addMinutes(25 + $position * 50))[1];
                }
            }
            for ($k = 0; $k < 1 + $index % 4; $k++) {
                $reactor = $people[(self::pick('reagisce:'.$event->id, $count) + $k * 2) % $count];
                if ($reactor->id === $author->id || EventCommentReaction::query()->where('event_comment_id', $comment->id)->where('user_id', $reactor->id)->exists()) {
                    continue;
                }
                (new EventCommentReaction)->forceFill(['event_comment_id' => $comment->id, 'user_id' => $reactor->id, 'type' => $types[($index + $k) % count($types)]])->save();
                $reactions++;
            }
        }
        $ids = array_map(fn (User $u): int => $u->id, $people);
        $this->report['Commenti alle schede (con risposte)'] = [$created, EventComment::query()->whereIn('user_id', $ids)->count()];
        $this->report['Reazioni ai commenti'] = [$reactions, EventCommentReaction::query()->whereIn('user_id', $ids)->count()];
    }

    /** @return array{0: EventComment, 1: bool} */
    private function eventComment(Event $event, User $user, string $body, ?EventComment $parent, CarbonImmutable $at): array
    {
        $existing = EventComment::query()->where('event_id', $event->id)->where('user_id', $user->id)->where('body', $body)->first();
        if ($existing !== null) {
            return [$existing, false];
        }
        $comment = new EventComment;
        $comment->forceFill(['event_id' => $event->id, 'user_id' => $user->id, 'parent_id' => $parent?->id, 'reply_to_id' => $parent?->id,
            'body' => $body, 'status' => EventCommentStatus::Published, 'revision' => 1, 'created_at' => $at, 'updated_at' => $at])->save();

        return [$comment, true];
    }

    /**
     * Informazioni pratiche e costi dichiarati, su due date su cinque: la
     * differenza fra una scheda curata e una normale deve vedersi.
     *
     * @param  Collection<int, EventOccurrence>  $dates
     */
    private function seedPractical(Collection $dates): void
    {
        $profiles = self::practicalProfiles();
        $touched = 0;
        $total = 0;
        foreach ($dates->values() as $index => $date) {
            if ($index % 5 > 1) {
                continue;
            }
            $total++;
            if ($date->practical_details !== null) {
                continue;
            }
            $profile = $profiles[self::pick('pratiche:'.$date->id, count($profiles))];
            $date->forceFill(['practical_details' => $profile['pratiche'], 'cost_breakdown' => $profile['costi']])->save();
            $touched++;
        }
        $this->report['Date con informazioni pratiche e costi'] = [$touched, $total];
    }

    /** @return list<array{pratiche: array<string, mixed>, costi: array<string, int>|null}> */
    private static function practicalProfiles(): array
    {
        return [
            ['pratiche' => ['age_groups' => ['all'], 'stroller' => 'yes', 'changing_table' => 'yes', 'kids_area' => 'no', 'membership' => 'not_required', 'accessibility' => 'yes',
                'accessibility_notes' => 'Ingresso a raso, bagno accessibile, posti riservati nelle prime file.',
                'parking_type' => 'paid', 'parking_notes' => 'Parcheggio a pagamento in piazza: 1,20 € l’ora fino alle 20, poi libero.',
                'transit_notes' => 'Autobus 3 e 12, fermata a duecento metri. Ultima corsa alle 00:30.',
                'entrance_notes' => 'Si entra dal cortile interno, non dal portone principale.',
                'food_notes' => 'Cucina aperta fino alle 22:30.', 'start_notes' => 'Si comincia puntuali: porte aperte mezz’ora prima.',
                'practical_custom' => [['label' => 'Guardaroba', 'icon' => 'briefcase', 'text' => 'Gratuito, subito dopo l’ingresso.']], 'feature_ids' => []],
                'costi' => ['admission' => 8, 'drink' => 4, 'membership' => 0, 'other' => 0]],
            ['pratiche' => ['age_groups' => ['18-plus'], 'membership' => 'required',
                'membership_notes' => 'Tessera dell’associazione: si fa in cassa, 5 € e vale un anno.',
                'accessibility' => 'no', 'accessibility_notes' => 'Sala al primo piano, senza ascensore.', 'parking_type' => 'none',
                'transit_notes' => 'Tram fino a Ponti Romani, poi dieci minuti a piedi.', 'start_notes' => 'Le porte aprono un’ora prima.',
                'practical_custom' => [], 'feature_ids' => []],
                'costi' => ['admission' => 10, 'drink' => 5, 'membership' => 5]],
            ['pratiche' => ['age_groups' => ['all', '6-10'], 'stroller' => 'yes', 'kids_area' => 'yes', 'accessibility' => 'yes', 'parking_type' => 'free',
                'parking_notes' => 'Parcheggio libero nel piazzale dietro il locale.',
                'entrance_notes' => 'Ingresso libero fino a esaurimento posti: non c’è prenotazione.', 'food_notes' => 'Banco con panini e bibite, prezzi da bar.',
                'practical_custom' => [['label' => 'Se piove', 'icon' => 'cloud', 'text' => 'Si sposta tutto sotto il portico: non si annulla.']], 'feature_ids' => []],
                'costi' => null],
            ['pratiche' => ['age_groups' => ['all'], 'accessibility' => 'yes', 'parking_type' => 'paid',
                'parking_notes' => 'Parcheggio Prato della Valle, 1,50 € l’ora. Rastrelliere per le bici davanti all’ingresso.',
                'transit_notes' => 'Tram SIR1, fermata Santo o Prato della Valle.', 'food_notes' => 'Bar interno con dolci e tisane.',
                'practical_custom' => [['label' => 'Bambini', 'icon' => 'heart', 'text' => 'Sotto i sei anni si entra gratis.']], 'feature_ids' => []],
                'costi' => ['admission' => 12, 'drink' => 0, 'other' => 3]],
        ];
    }

    /**
     * Le visite alle schede negli ultimi giorni: sono quelle che ordinano
     * «più viste» e riempiono i grafici del pannello. Spariscono con la data.
     *
     * @param  Collection<int, EventOccurrence>  $dates
     */
    private function seedViews(Collection $dates): void
    {
        if (! Schema::hasTable('occurrence_views_daily')) {
            return;
        }
        $written = 0;
        $today = CarbonImmutable::today();
        foreach ($dates as $date) {
            $base = 15 + self::pick('visite:'.$date->id, 180);
            for ($day = 6; $day >= 0; $day--) {
                $views = (int) round($base * (0.5 + (6 - $day) / 8));
                $written += DB::table('occurrence_views_daily')->insertOrIgnore([
                    'occurrence_id' => $date->id, 'date' => $today->subDays($day)->toDateString(), 'views' => $views,
                    'direction_clicks' => intdiv($views, 9), 'ticket_clicks' => intdiv($views, 14), 'shares' => intdiv($views, 25),
                    'calendar_clicks' => intdiv($views, 18), 'poster_clicks' => intdiv($views, 11), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        $this->report['Giorni di visite alle schede'] = [$written, DB::table('occurrence_views_daily')->whereIn('occurrence_id', $dates->modelKeys())->count()];
    }

    /**
     * Le visite giornaliere degli eventi nuovi, sulle stesse due settimane
     * delle condivisioni.
     *
     * Prima si seminavano solo le visite alle date: nelle statistiche
     * dell'evento risultavano cinque visite contro mille condivisioni, numeri
     * che nessuno avrebbe preso sul serio. Solo eventi nuovi di questo
     * comando: le righe se ne vanno con l'evento, in cascata.
     *
     * @param  Collection<int, EventOccurrence>  $dates
     */
    private function seedEventViews(Collection $dates): void
    {
        if (! Schema::hasTable('event_views_daily')) {
            return;
        }
        $events = $this->newDemoEvents($dates);
        $written = 0;
        $today = CarbonImmutable::today();
        foreach ($events as $event) {
            $base = 60 + self::pick('visite-evento:'.$event->id, 260);
            for ($day = 13; $day >= 0; $day--) {
                $views = (int) round($base * (0.4 + (13 - $day) / 16));
                $written += DB::table('event_views_daily')->insertOrIgnore([
                    'event_id' => $event->id, 'date' => $today->subDays($day)->toDateString(), 'views' => $views, 'unique_views' => (int) round($views * 0.7),
                    'direction_clicks' => intdiv($views, 9), 'ticket_clicks' => intdiv($views, 14), 'shares' => intdiv($views, 25),
                    'calendar_clicks' => intdiv($views, 18), 'poster_clicks' => intdiv($views, 11), 'website_clicks' => intdiv($views, 30),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        $this->report['Giorni di visite agli eventi'] = [$written, $events->count().' eventi'];
    }

    /**
     * Tre campagne dimostrative già concluse, con due settimane di misure:
     * riempiono le statistiche delle sponsorizzazioni di redazione, locali e
     * organizzazioni.
     *
     * **Concluse apposta.** Una campagna in corso comparirebbe sul sito con
     * la scritta «Sponsorizzato» e il nome di un locale vero che non ha
     * pagato niente: finita ieri, resta solo nei pannelli. Stanno su eventi
     * nuovi di questo comando e se ne vanno con loro, in cascata.
     *
     * @param  Collection<int, EventOccurrence>  $dates
     */
    private function seedSponsorshipStats(Collection $dates): void
    {
        if (! Schema::hasTable('sponsorship_daily_stats')) {
            return;
        }
        $events = $this->newDemoEvents($dates)->unique('venue_id')->take(3)->values();
        $placements = [SponsorshipPlacement::HomeHero, SponsorshipPlacement::ListTop, SponsorshipPlacement::MapSheet];
        $today = CarbonImmutable::today();
        $written = 0;
        foreach ($events as $position => $event) {
            $campaign = Sponsorship::query()->firstOrCreate(
                ['event_id' => $event->id, 'placement' => $placements[$position], 'advertiser_name' => self::DEMO_ADVERTISER],
                ['city_id' => $event->city_id, 'status' => SponsorshipStatus::Active, 'starts_at' => $today->subDays(14)->utc(), 'ends_at' => $today->subSecond()->utc(),
                    'weight' => 1, 'priority' => 0, 'currency' => 'EUR', 'notes' => 'demo:quindicina'],
            );
            $impressions = 0;
            $clicks = 0;
            for ($day = 14; $day >= 1; $day--) {
                $views = 120 + self::pick('sponsor:'.$campaign->id.':'.$day, 380);
                $opened = intdiv($views, 40 + $position * 10) + ($day % 3);
                $written += DB::table('sponsorship_daily_stats')->insertOrIgnore([
                    'sponsorship_id' => $campaign->id, 'day' => $today->subDays($day)->toDateString(),
                    'impressions' => $views, 'clicks' => $opened, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $totals = DB::table('sponsorship_daily_stats')->where('sponsorship_id', $campaign->id)
                ->selectRaw('COALESCE(SUM(impressions), 0) as impressions, COALESCE(SUM(clicks), 0) as clicks')->first();
            $campaign->forceFill(['impressions' => (int) ($totals->impressions ?? 0), 'clicks' => (int) ($totals->clicks ?? 0)])->saveQuietly();
        }
        $this->report['Giorni di sponsorizzazioni dimostrative'] = [$written, $events->count().' campagne concluse'];
    }

    /**
     * Gli eventi creati da questo comando fra quelli delle date.
     *
     * @param  Collection<int, EventOccurrence>  $dates
     * @return \Illuminate\Support\Collection<int, Event>
     */
    private function newDemoEvents(Collection $dates): \Illuminate\Support\Collection
    {
        return $dates->pluck('event')->filter(fn (?Event $event): bool => $event !== null && str_starts_with((string) $event->source_ref, self::PREFIX))
            ->unique('id')->values();
    }

    /**
     * Link tracciati e due settimane di condivisioni su sei eventi nuovi.
     *
     * @param  Collection<int, EventOccurrence>  $dates
     */
    private function seedShares(Collection $dates): void
    {
        $events = $dates->pluck('event')->filter(fn (?Event $event): bool => $event !== null && str_starts_with((string) $event->source_ref, self::PREFIX))
            ->unique('id')->take(6)->values();
        $written = 0;
        $today = CarbonImmutable::today();
        foreach ($events as $position => $event) {
            app(EventShares::class)->links($event, null);
            foreach (EventShareLink::query()->where('event_id', $event->id)->orderBy('id')->get() as $index => $link) {
                for ($day = 13; $day >= 0; $day--) {
                    $shares = max(0, 3 - $index + (($day + $position) % 4));
                    $written += DB::table('event_share_daily')->insertOrIgnore([[
                        'share_link_id' => $link->id, 'date' => $today->subDays($day)->toDateString(),
                        'shares' => $shares, 'clicks' => $shares * 2 + (($day + $index) % 5),
                    ]]);
                }
            }
        }
        $this->report['Giorni di condivisioni'] = [$written, $events->count().' eventi'];
    }

    /**
     * Un sondaggio fra amici per ogni rassegna con almeno due date future:
     * «quale sera andiamo?» ha senso solo dove le sere sono più d'una.
     *
     * @param  Collection<int, EventOccurrence>  $dates
     * @param  list<User>  $people
     */
    private function seedPolls(Collection $dates, array $people): void
    {
        if (! config('community.enabled') || ! Schema::hasTable('event_polls')) {
            $this->report['Sondaggi fra amici'] = ['saltati', 'la comunità è spenta'];

            return;
        }
        $created = 0;
        $votes = 0;
        $links = [];
        $count = count($people);
        $groups = $dates->filter(fn (EventOccurrence $date): bool => $date->starts_at->greaterThan(now()->addHours(3)))
            ->groupBy('event_id')->filter(fn ($group): bool => $group->count() >= EventPolls::MIN_OPTIONS);
        foreach ($groups->values() as $index => $group) {
            $event = $group->first()->event;
            $note = self::POLL_NOTE.'quale sera andiamo a «'.Str::limit((string) $event?->title, 60).'»?';
            $poll = EventPoll::query()->where('note', $note)->first();
            if ($poll !== null) {
                $links[] = $this->pollUrl($poll->token);

                continue;
            }
            $owner = $people[self::pick('sondaggio:'.$group->first()->event_id, $count)];
            try {
                $options = $group->sortBy(fn (EventOccurrence $d): int => $d->starts_at->getTimestamp())->take(EventPolls::MAX_OPTIONS)->modelKeys();
                $closes = CarbonImmutable::instance($group->min('starts_at'))->subHours(2);
                $closes = $closes->greaterThan(now()->addDays(EventPolls::MAX_DAYS)) ? CarbonImmutable::now()->addDays(EventPolls::MAX_DAYS - 1) : $closes;
                $poll = app(EventPolls::class)->create($owner, $options, $note, $closes);
                $created++;
                foreach (range(1, 3 + $index % 4) as $k) {
                    $voter = $people[(self::pick('vota:'.$poll->id, $count) + $k * 3) % $count];
                    foreach ($poll->options->slice(0, 1 + ($k % $poll->options->count())) as $option) {
                        try {
                            app(EventPolls::class)->toggle($voter, $poll, $option);
                            $votes++;
                        } catch (Throwable) {
                            // Tetto dei partecipanti raggiunto: il sondaggio resta com'è.
                        }
                    }
                }
                $links[] = $this->pollUrl($poll->token);
            } catch (Throwable $error) {
                $this->warn('Sondaggio non creato per «'.$event?->title.'»: '.$error->getMessage());
            }
        }
        $this->report['Sondaggi fra amici'] = [$created.' ('.$votes.' voti)', count($links)];
        foreach ($links as $link) {
            $this->line('  sondaggio: '.$link);
        }
    }

    private function pollUrl(string $token): string
    {
        return Route::has('polls.show') ? route('polls.show', $token) : url('/sondaggi/'.$token);
    }

    /**
     * Lista d'attesa con un posto promosso e staff agli ingressi, sulla
     * stessa sequenza di `demo:crescita`: capienza piena, gruppi in coda, un
     * annullamento che fa avanzare la coda. Su una data a una settimana
     * buona, in un locale che ha già la biglietteria accesa.
     *
     * @param  Collection<int, EventOccurrence>  $dates
     * @param  list<User>  $people
     */
    private function seedTicketing(Collection $dates, array $people): void
    {
        $hours = config()->integer('ticketing.promotion.min_hours_before') + 1;
        $suitable = $dates->filter(fn (EventOccurrence $date): bool => (bool) $date->effectiveVenue()?->ticketing_enabled && $date->starts_at->greaterThan(now()->addHours($hours)));
        $date = $suitable->first(fn (EventOccurrence $d): bool => $d->starts_at->greaterThan(now()->addDays(7))) ?? $suitable->last();
        if ($date === null) {
            $this->report['Lista d’attesa'] = ['saltata', 'nessuna data in un locale con biglietteria'];

            return;
        }
        $staff = array_slice($people, 6, 2);
        if (Booking::query()->where('occurrence_id', $date->id)->exists()) {
            $this->extendPromotion($date);
            $date->checkinStaff()->syncWithoutDetaching(array_map(fn (User $u): int => $u->id, $staff));
            $this->report['Lista d’attesa'] = ['già presente', $this->ticketingSummary($date)];

            return;
        }
        $service = app(TicketingService::class);
        try {
            $service->configure($date, ['booking_enabled' => true, 'booking_capacity' => 6, 'booking_limit' => 2, 'booking_waitlist' => true,
                'booking_opens_at' => now()->subDay(), 'booking_closes_at' => $date->starts_at], $people[0]);
            $bookers = array_slice($people, 10, 5);
            $bookings = [];
            foreach ($bookers as $person) {
                $bookings[] = $service->reserve($person, $date->fresh() ?? $date, [$person->name, 'Ospite di '.$person->name], (string) Str::uuid(), true);
            }
            $service->cancel($bookings[0], $bookers[0], null, false, 'Quindicina: posto liberato per mostrare la coda');
            $this->extendPromotion($date);
            $date->checkinStaff()->syncWithoutDetaching(array_map(fn (User $u): int => $u->id, $staff));
            $this->report['Lista d’attesa'] = ['creata', $this->ticketingSummary($date)];
        } catch (Throwable $error) {
            $this->report['Lista d’attesa'] = ['non riuscita', $error->getMessage()];
        }
    }

    /** @see ShowcaseGrowthCommand::extendPromotion() */
    private function extendPromotion(EventOccurrence $date): void
    {
        $deadline = $date->starts_at->subHours(config()->integer('ticketing.promotion.min_hours_before'));
        Booking::query()->where('occurrence_id', $date->id)->whereNotNull('promotion_expires_at')
            ->where('promotion_expires_at', '<', $deadline)->update(['promotion_expires_at' => $deadline]);
    }

    private function ticketingSummary(EventOccurrence $date): string
    {
        $count = fn (BookingStatus $status): int => Booking::query()->where('occurrence_id', $date->id)->where('status', $status)->count();

        return sprintf('%s del %s — %d confermate, %d in coda, %d annullate', $date->event?->title, $date->starts_at->timezone(config('app.timezone'))->format('d/m'),
            $count(BookingStatus::Confirmed), $count(BookingStatus::Waitlisted), $count(BookingStatus::Cancelled));
    }

    /**
     * I passaggi: un catalogo costruito sulle date della quindicina e passato
     * a `ShowcaseRides`, che li scrive con gli stessi vincoli del servizio.
     *
     * Una data su tre ha un'offerta all'andata, una su due di quelle anche il
     * ritorno. Le richieste coprono i quattro stati, e le accettate hanno la
     * loro chat. Le recensioni stanno su viaggi già conclusi, scritti da e per
     * le persone nuove: quelle della vetrina hanno già le loro.
     *
     * @param  array{people: list<mixed>, ride_zones: list<array{0: string, 1: list<string>}>, ride_notes: list<string>, ride_request_notes: list<string|null>, ride_messages: list<array{0: string, 1: string}>, ride_reviews: list<array{0: int, 1: string}>}  $catalog
     * @param  list<User>  $people
     * @param  Collection<int, EventOccurrence>  $dates
     */
    private function seedRides(City $city, array $catalog, array $people, Collection $dates): void
    {
        if (! Schema::hasTable('ride_offers')) {
            $this->report['Passaggi (car pooling)'] = [0, 'non disponibile'];

            return;
        }
        $count = count($people);
        $events = [];
        $rides = [];
        $requests = [];
        $messages = [];
        $statuses = ['accepted', 'pending', 'accepted', 'declined', 'pending', 'withdrawn'];
        $busy = [];
        $candidates = $dates->filter(fn (EventOccurrence $date): bool => $date->starts_at->greaterThan(now()->addHours(4)))->values();
        foreach ($candidates as $index => $date) {
            if ($index % 3 !== 0) {
                continue;
            }
            $eventIndex = count($events);
            $events[$eventIndex] = $date;
            $local = CarbonImmutable::instance($date->starts_at)->timezone($city->timezone);
            $driver = self::pick('autista:'.$date->id, $count);
            [$zone, $stops] = $catalog['ride_zones'][self::pick('zona:'.$date->id, count($catalog['ride_zones']))];
            $legs = [['outbound', $local->subMinutes(35 + self::pick('anticipo:'.$date->id, 4) * 10)->format('H:i')]];
            if ($index % 2 === 0) {
                $end = $date->ends_at !== null ? CarbonImmutable::instance($date->ends_at)->timezone($city->timezone) : $local->addHours(2);
                $legs[] = ['return', $end->addMinutes(15)->format('H:i')];
            }
            foreach ($legs as [$leg, $time]) {
                $capacity = 3 + self::pick('posti:'.$date->id.$leg, 2);
                $rideIndex = count($rides);
                $details = ['note' => $catalog['ride_notes'][self::pick('nota:'.$date->id.$leg, count($catalog['ride_notes']))], 'stops' => $stops];
                if ($index % 7 === 0) {
                    $details += ['accessibility' => 'folding_chair', 'accessibility_note' => 'Nel bagagliaio entra una sedia a rotelle pieghevole.'];
                }
                $rides[] = [$driver, $eventIndex, $leg, $zone, $time, $capacity, $details];
                $busy[$eventIndex.':'.$leg] = [$driver => true];
                $taken = 0;
                for ($k = 0; $k < 1 + self::pick('richieste:'.$date->id.$leg, 3); $k++) {
                    $passenger = ($driver + 1 + $k * 4 + self::pick('passeggero:'.$date->id, 3)) % $count;
                    if (isset($busy[$eventIndex.':'.$leg][$passenger])) {
                        continue;
                    }
                    $status = $statuses[($rideIndex + $k) % count($statuses)];
                    $seats = $k === 0 && $capacity > 3 ? 2 : 1;
                    if ($status === 'accepted' && $taken + $seats >= $capacity) {
                        $status = 'pending';
                    }
                    if (in_array($status, ['accepted', 'pending'], true)) {
                        $busy[$eventIndex.':'.$leg][$passenger] = true;
                    }
                    $taken += $status === 'accepted' ? $seats : 0;
                    $requestIndex = count($requests);
                    $requests[] = [$rideIndex, $passenger, $seats, $status, $catalog['ride_request_notes'][self::pick('richiesta:'.$date->id.$k, count($catalog['ride_request_notes']))]];
                    if ($status === 'accepted') {
                        foreach (array_slice($catalog['ride_messages'], 0, 2 + $requestIndex % 4) as [$author, $body]) {
                            $messages[] = [$requestIndex, $author, $body];
                        }
                    }
                }
            }
        }

        // Le recensioni: conducente fra le persone nuove, passeggero fra le altre.
        $showcase = count(ShowcaseDemoCommand::catalog()['people']);
        $reviews = [];
        foreach ($catalog['ride_reviews'] as $index => [$rating, $body]) {
            $driver = $showcase + $index % max(1, $count - $showcase);
            $passenger = ($index * 5 + 2) % $showcase;
            if ($driver < $count) {
                $reviews[] = [$driver, $passenger, $catalog['ride_zones'][$index % count($catalog['ride_zones'])][0], $rating, $body];
            }
        }

        $rideCatalog = ['people' => $people, 'events' => $events, 'rides' => $rides, 'ride_requests' => $requests, 'ride_messages' => $messages, 'ride_reviews' => $reviews];
        ShowcaseRides::validate($rideCatalog);
        $service = app(ShowcaseRides::class);
        $investor = EventOccurrence::query()->whereIn('event_id', Event::query()->where('city_id', $city->id)->where('status', 'published')
            ->where('source_ref', 'like', InvestorDemoCommand::PREFIX.'%')->select('id'));
        // Le prime date concluse le usa la vetrina per le sue recensioni: si parte dopo.
        $showcaseReviews = count(ShowcaseDemoCommand::catalog()['ride_reviews']);
        $past = $service->pastDates($investor, $showcaseReviews + count($reviews))->slice($showcaseReviews)->values();
        foreach ($service->seed($city, $rideCatalog, $people, $events, $past) as $label => $row) {
            $this->report[$label] = $row;
        }
    }

    /**
     * Toglie ciò che questo comando ha aggiunto: gli eventi nuovi, le date
     * nuove degli eventi esistenti con tutto ciò che vi è appeso, i sondaggi,
     * i commenti scritti sulle schede degli eventi esistenti e le persone
     * nuove. Le persone della vetrina restano: sono di `demo:showcase`.
     *
     * @param  array<string, mixed>  $catalog
     */
    private function purge(City $city, array $catalog): int
    {
        $events = Event::withTrashed()->where('city_id', $city->id)->where('is_demo', true)->where('source_ref', 'like', self::PREFIX.'%')->get();
        $reused = EventOccurrence::query()->whereIn('id', $this->registeredReusedDates())->get();
        $users = User::withTrashed()->whereIn('email', self::emails($catalog))->get();
        $newDates = EventOccurrence::withTrashed()->whereIn('event_id', $events->modelKeys())->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $dateIds = [...$newDates, ...$reused->modelKeys()];
        $userIds = $users->modelKeys();

        $live = Schema::hasTable('ride_offers') ? RideOffer::query()->whereIn('occurrence_id', $dateIds)
            ->where('departure_at', '>', now())->whereNotIn('driver_id', User::query()->where('email', 'like', '%@'.ShowcaseDemoCommand::EMAIL_DOMAIN)->select('id'))->count() : 0;
        $this->info(sprintf('Da togliere: %d eventi nuovi (%d date), %d date aggiunte a eventi esistenti, %d persone nuove.', $events->count(), count($newDates), $reused->count(), $users->count()));
        if ($live > 0) {
            $this->warn("Sulle date ci sono {$live} passaggi offerti da persone vere, ancora da fare: verranno tolti anche quelli.");
        }
        if ($this->option('dry-run')) {
            $this->line('Prova a vuoto: nessuna scrittura.');

            return self::SUCCESS;
        }

        return Cache::lock('fortnight-demo:'.$city->id, 3600)->block(5, function () use ($city, $catalog, $events, $reused, $users, $dateIds, $userIds): int {
            $people = User::query()->where('email', 'like', '%@'.ShowcaseDemoCommand::EMAIL_DOMAIN)->pluck('id')->all();
            $texts = [];
            foreach ($catalog['comments'] as $threads) {
                foreach ($threads as [$question, $answers]) {
                    $texts = [...$texts, $question, ...$answers];
                }
            }
            $morph = (new User)->getMorphClass();

            DB::transaction(function () use ($reused, $dateIds, $userIds, $people, $texts, $morph): void {
                if (Schema::hasTable('ride_offers')) {
                    app(ShowcaseRides::class)->purgeDates($userIds, $dateIds);
                }
                EventPoll::query()->where('note', 'like', self::POLL_NOTE.'%')->get()->each(fn (EventPoll $poll) => $poll->delete());
                // Le schede degli eventi esistenti restano: si tolgono solo i commenti di questo catalogo.
                EventComment::query()->whereIn('event_id', $reused->pluck('event_id'))->whereIn('user_id', $people)->whereIn('body', $texts)->whereNotNull('parent_id')->delete();
                EventComment::query()->whereIn('event_id', $reused->pluck('event_id'))->whereIn('user_id', $people)->whereIn('body', $texts)->delete();
                foreach (Booking::query()->whereIn('occurrence_id', $dateIds)->get() as $booking) {
                    $booking->tickets()->delete();
                    $booking->delete();
                }
                // Salvataggi, post, «ci vado», visite e staff seguono la data in cascata.
                $reused->each(fn (EventOccurrence $date) => $date->forceDelete());
                DB::table('followables')->where('followable_type', $morph)->whereIn('followable_id', $userIds)->delete();
                DB::table('notifications')->where('notifiable_type', $morph)->whereIn('notifiable_id', $userIds)->delete();
            });
            $users->each(fn (User $user) => $user->forceDelete());
            // Uno per uno: così la libreria media cancella anche i file delle locandine.
            $events->each(fn (Event $event) => $event->forceDelete());
            Setting::query()->where('key', self::SETTING)->delete();
            ContentVersion::bump($city);
            $this->info(sprintf('Tolti %d eventi, %d date aggiunte e %d persone. Le locandine sostituite sugli eventi esistenti restano.', $events->count(), $reused->count(), $users->count()));

            return self::SUCCESS;
        });
    }
}
