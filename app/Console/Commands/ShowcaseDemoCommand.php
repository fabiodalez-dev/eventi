<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CommunityStatus;
use App\Enums\EventCommentStatus;
use App\Enums\PostIntent;
use App\Enums\ProfileVisibility;
use App\Enums\SavedVisibility;
use App\Enums\VenueReviewStatus;
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
use App\Models\VenueReview;
use App\Notifications\CommunityNotification;
use App\Services\Community\WhatsappVerification;
use App\Support\ContentVersion;
use Carbon\CarbonImmutable;
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
 * post, commenti, reazioni e recensioni. Serve a mostrare le funzioni nuove,
 * non a simulare traffico reale.
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
 * Action produrrebbero.
 *
 * `--purge` rimuove esattamente le persone del catalogo (con tutto ciò che
 * dipende da loro) e gli eventi con il prefisso: niente altro.
 */
final class ShowcaseDemoCommand extends Command
{
    public const PREFIX = 'showcase-demo-v1:';

    public const EMAIL_DOMAIN = 'demo.incitta.invalid';

    protected $signature = 'demo:showcase {city=padova} {--dry-run} {--allow-production} {--purge : Rimuove tutto ciò che questo comando ha creato}';

    protected $description = 'Popola la città con una settimana dimostrativa: 25 eventi, persone verificate, post, commenti, reazioni e recensioni';

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
                ['Recensioni dei locali', count($catalog['venue_reviews'])],
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
            $people = $this->seedPeople($city, $catalog['people']);
            $this->seedFollows($city, $people, $venues);
            $this->seedBlocks($people);
            $posts = $this->seedPosts($city, $week, $catalog['posts'], $people, $events);
            $this->seedPrivateSaves($people, $events);
            $this->seedPostComments($catalog['post_comments'], $people, $posts);
            $this->seedEventComments($catalog['event_comments'], $people, $events);
            $this->seedVenueReviews($catalog['venue_reviews'], $people, $venues);
            $this->seedRides($city, $people, $events);
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
     * @return array{events: list<array<string, mixed>>, people: list<array{handle: string, name: string, bio: string, visibility: string, featured: bool}>, posts: list<array{0: int, 1: int|string, 2: string, 3: string}>, post_comments: list<array{0: int, 1: int, 2: string, 3: int|null}>, event_comments: list<array{0: int, 1: int, 2: string, 3: list<array{0: int, 1: string}>, 4: list<array{0: int, 1: string}>}>, venue_reviews: list<array{0: int, 1: int, 2: int, 3: string}>}
     */
    public static function catalog(): array
    {
        /** @var array{events: list<array<string, mixed>>, people: list<array{handle: string, name: string, bio: string, visibility: string, featured: bool}>, posts: list<array{0: int, 1: int|string, 2: string, 3: string}>, post_comments: list<array{0: int, 1: int, 2: string, 3: int|null}>, event_comments: list<array{0: int, 1: int, 2: string, 3: list<array{0: int, 1: string}>, 4: list<array{0: int, 1: string}>}>, venue_reviews: list<array{0: int, 1: int, 2: int, 3: string}>} $catalog */
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
     * Il lunedì della settimana prossima nel fuso della città. Se il catalogo
     * è già stato importato si riparte dalla sua settimana: una seconda
     * esecuzione completa quella, non ne apre un'altra.
     */
    private function weekStart(City $city): CarbonImmutable
    {
        $first = EventOccurrence::query()
            ->whereIn('event_id', Event::withTrashed()->where('city_id', $city->id)->where('source_ref', 'like', self::PREFIX.'%')->select('id'))
            ->orderBy('starts_at')->value('starts_at');

        if ($first !== null) {
            return CarbonImmutable::parse($first)->timezone($city->timezone)->startOfWeek(CarbonImmutable::MONDAY);
        }

        return CarbonImmutable::now($city->timezone)->next(CarbonImmutable::MONDAY)->startOfDay();
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
                    'notification_preferences' => ['reminders' => false, 'sold_out' => false, 'venue_digest' => false, 'daily_digest' => false, 'comments' => false],
                ]);
                $phone = self::phone($index);
                $verifiedAt = CarbonImmutable::now()->subDays(20 - $index % 15);
                $user->forceFill([
                    'email_verified_at' => $verifiedAt, 'whatsapp_phone' => $phone, 'whatsapp_phone_hash' => $fingerprints->fingerprint($phone),
                    'whatsapp_verified_at' => $verifiedAt, 'whatsapp_prompted_at' => $verifiedAt, 'last_active_at' => now()->subHours($index),
                ])->save();
                $created++;
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
     * I locali seguiti non notificano: `notify` resta spento.
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
     * @param  list<array{0: int, 1: int, 2: int, 3: string}>  $rows
     * @param  array<int, User>  $people
     * @param  Collection<int, Venue>  $venues
     */
    private function seedVenueReviews(array $rows, array $people, Collection $venues): void
    {
        $created = 0;
        foreach ($rows as [$person, $position, $rating, $body]) {
            $user = $people[$person] ?? null;
            $venue = $venues[$position % $venues->count()];
            if ($user === null || VenueReview::query()->where('venue_id', $venue->id)->where('user_id', $user->id)->exists()) {
                continue;
            }
            (new VenueReview)->forceFill(['venue_id' => $venue->id, 'user_id' => $user->id, 'rating' => $rating, 'body' => $body,
                'status' => VenueReviewStatus::Approved, 'revision' => 1, 'moderated_at' => now()])->save();
            $created++;
        }
        $this->report['Recensioni dei locali'] = [$created, VenueReview::query()->whereIn('user_id', array_map(fn (User $u): int => $u->id, $people))->count()];
    }

    /**
     * Punto di estensione per il car pooling (PR #94, non ancora su main).
     *
     * Quando tabelle e modelli dei passaggi arriveranno, qui si creano offerte
     * di passaggio verso gli eventi della settimana ($events), richieste e
     * recensioni fra le persone demo ($people). Le righe dovranno dipendere
     * dagli utenti con chiavi esterne in cascata, così `--purge` le porta via
     * senza modifiche; altrimenti vanno aggiunte a `purge()`.
     *
     * @param  array<int, User>  $people
     * @param  array<int, EventOccurrence>  $events
     */
    private function seedRides(City $city, array $people, array $events): void
    {
        if (! Schema::hasTable('rides') || ! class_exists('App\\Models\\Ride')) {
            $this->report['Passaggi (car pooling)'] = [0, 'non disponibile'];

            return;
        }
        $this->report['Passaggi (car pooling)'] = [0, 'da implementare'];
    }

    /**
     * Un avviso in app, come quello di CommunityNotification, scritto
     * direttamente nella tabella: nessun canale di consegna viene toccato.
     */
    private function notify(User $recipient, string $kind, string $url, ?int $actorId = null): int
    {
        $recipient->notifications()->create([
            'id' => (string) Str::uuid(), 'type' => CommunityNotification::class,
            'data' => (new CommunityNotification($kind, $url, $actorId))->toArray($recipient), 'read_at' => null,
        ]);

        return 1;
    }

    /** @param array{people: list<array{handle: string}>} $catalog */
    private function purge(City $city, array $catalog): int
    {
        $users = User::withTrashed()->whereIn('email', self::emails($catalog))->get();
        $events = Event::withTrashed()->where('city_id', $city->id)->where('is_demo', true)->where('source_ref', 'like', self::PREFIX.'%')->get();
        $this->info(sprintf('Da rimuovere: %d persone demo e %d eventi della vetrina.', $users->count(), $events->count()));
        if ($this->option('dry-run')) {
            $this->line('Prova a vuoto: nessuna scrittura.');

            return self::SUCCESS;
        }

        return Cache::lock('showcase-demo:'.$city->id, 3600)->block(5, function () use ($users, $events, $city): int {
            $ids = $users->pluck('id')->all();
            $morph = (new User)->getMorphClass();
            DB::transaction(function () use ($ids, $morph, $users): void {
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
                // Il resto (profili, post, commenti, relazioni, blocchi, salvataggi, reazioni, recensioni) va in cascata.
                $users->each(fn (User $user) => $user->forceDelete());
            });
            // Uno per uno: così la libreria media cancella anche i file delle locandine.
            $events->each(fn (Event $event) => $event->forceDelete());
            ContentVersion::bump($city);
            $this->info(sprintf('Rimossi %d persone e %d eventi. Nient’altro è stato toccato.', count($ids), $events->count()));

            return self::SUCCESS;
        });
    }
}
