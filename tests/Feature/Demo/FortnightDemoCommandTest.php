<?php

declare(strict_types=1);

use App\Console\Commands\FortnightDemoCommand;
use App\Console\Commands\InvestorDemoCommand;
use App\Console\Commands\ShowcaseDemoCommand;
use App\Enums\EventStatus;
use App\Enums\VenueType;
use App\Models\Category;
use App\Models\CommunityPost;
use App\Models\Event;
use App\Models\EventComment;
use App\Models\EventOccurrence;
use App\Models\EventPoll;
use App\Models\RideOffer;
use App\Models\RideRequest;
use App\Models\SavedEvent;
use App\Models\Setting;
use App\Models\Sponsorship;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Database\Seeders\EventFeatureSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * La quindicina dimostrativa: eventi nuovi con foto, date nuove per eventi
 * passati, e tutto ciò che la piattaforma mostra attorno a una data.
 *
 * Le prove guardano tre cose: che ogni funzione arrivi davvero sulle date
 * della quindicina, che nessuno riceva niente, e che `--purge` tolga ciò che
 * il comando ha aggiunto senza toccare lo storico degli eventi riusati.
 */
function fortnightCounts(): array
{
    $ids = User::query()->where('email', 'like', '%@'.ShowcaseDemoCommand::EMAIL_DOMAIN)->pluck('id');

    return [
        'events' => Event::query()->where('source_ref', 'like', FortnightDemoCommand::PREFIX.'%')->count(),
        'occurrences' => EventOccurrence::query()->count(),
        'users' => $ids->count(),
        'attendances' => DB::table('community_attendances')->count(),
        'saved' => SavedEvent::query()->count(),
        'posts' => CommunityPost::query()->count(),
        'comments' => EventComment::query()->count(),
        'polls' => EventPoll::query()->count(),
        'offers' => RideOffer::query()->count(),
        'requests' => RideRequest::query()->count(),
    ];
}

beforeEach(function (): void {
    Queue::fake();
    Storage::fake('public');
    Mail::fake();
    // Niente Notification::fake(): la prova deve passare dal vero NotificationSender,
    // quello che il comando ferma per le persone demo.
    config(['community.enabled' => true, 'community.phone_hash_key' => 'test-fingerprint-key']);
    $this->city = testCity(['is_active' => true]);
    $this->seed([CategorySeeder::class, EventFeatureSeeder::class]);
    foreach (VenueType::cases() as $type) {
        Venue::factory()->create(['city_id' => $this->city->id, 'type' => $type, 'status' => 'approved', 'ticketing_enabled' => $type === VenueType::Teatro]);
    }
    // Mercoledì 18 novembre, a metà mattina: le date del mattino di oggi sono già passate.
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00', 'Europe/Rome'));

    // Eventi demo passati da riportare in cartellone, con la locandina di categoria.
    $this->old = collect(['cinema', 'musica-dal-vivo', 'teatro-e-danza', 'food-e-sagre'])->map(function (string $slug, int $i): Event {
        $category = Category::query()->where('slug', $slug)->firstOrFail();
        $event = Event::factory()->create(['city_id' => $this->city->id, 'category_id' => $category->id, 'venue_id' => Venue::query()->first()->id,
            'is_demo' => true, 'status' => EventStatus::Published, 'published_at' => now(), 'title' => 'Evento passato '.$i, 'source_ref' => InvestorDemoCommand::PREFIX.'010'.$i]);
        $event->addMedia(database_path('seeders/investor-media/'.$slug.'.jpg'))->preservingOriginal()
            ->withCustomProperties(['illustrative' => true])->toMediaCollection('poster');
        EventOccurrence::factory()->create(['event_id' => $event->id, 'starts_at' => CarbonImmutable::parse('2026-10-1'.$i.' 21:00', 'Europe/Rome')->utc(),
            'ends_at' => CarbonImmutable::parse('2026-10-1'.$i.' 23:00', 'Europe/Rome')->utc(), 'doors_at' => null]);

        return $event;
    });
});

it('refuses to run in production without explicit permission', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('demo:quindicina', ['city' => $this->city->slug])->assertFailed();
    expect(Event::query()->where('source_ref', 'like', FortnightDemoCommand::PREFIX.'%')->count())->toBe(0);
});

it('fills the next fifteen days with new events, new dates for past ones and everything around them', function (): void {
    $this->artisan('demo:quindicina', ['city' => $this->city->slug, '--riusati' => 4])->assertSuccessful();

    $catalog = FortnightDemoCommand::catalog();
    $new = Event::query()->where('source_ref', 'like', FortnightDemoCommand::PREFIX.'%')->with('media')->get();
    expect($new)->toHaveCount(count($catalog['events']))
        ->and($new->every(fn (Event $event): bool => $event->is_demo && $event->hasMedia('poster')))->toBeTrue()
        ->and($new->pluck('media')->flatten()->pluck('file_name')->unique()->count())->toBe($new->count());

    // Tutte le date nuove stanno nella quindicina e nessuna è già passata.
    $first = CarbonImmutable::parse('2026-11-18 00:00', 'Europe/Rome');
    $dates = EventOccurrence::query()->whereIn('event_id', $new->modelKeys())->get();
    expect($dates->every(fn (EventOccurrence $d): bool => $d->starts_at->isFuture() && $d->starts_at->lessThan($first->addDays(15))))->toBeTrue();

    // Gli eventi passati tornano con una data nuova, registrata per il purge, e la locandina della raccolta.
    $reused = Setting::query()->where('key', FortnightDemoCommand::SETTING)->first()?->typed_value;
    expect($reused)->toHaveCount(4);
    foreach ($this->old as $event) {
        expect($event->occurrences()->where('starts_at', '>', now())->count())->toBe(1)
            ->and($event->fresh()->getFirstMedia('poster')->file_name)->toStartWith('pool-');
    }

    // Persone della vetrina e nuove, con tutto il resto.
    $people = User::query()->where('email', 'like', '%@'.ShowcaseDemoCommand::EMAIL_DOMAIN)->get();
    expect($people)->toHaveCount(count(ShowcaseDemoCommand::catalog()['people']) + count($catalog['people']))
        ->and($people->every(fn (User $u): bool => $u->whatsapp_verified_at !== null && ! $u->canReceiveNotifications()))->toBeTrue()
        ->and(DB::table('community_attendances')->count())->toBeGreaterThan(50)
        ->and(CommunityPost::query()->count())->toBeGreaterThan(10)
        ->and(EventComment::query()->whereNotNull('parent_id')->count())->toBeGreaterThan(10)
        ->and(EventPoll::query()->where('note', 'like', 'Quindicina%')->count())->toBeGreaterThan(3)
        ->and(RideOffer::query()->where('status', 'open')->count())->toBeGreaterThan(10)
        ->and(RideRequest::query()->distinct()->pluck('status')->map(fn ($s) => $s->value)->sort()->values()->all())->toBe(['accepted', 'declined', 'pending', 'withdrawn'])
        ->and(DB::table('chat_messages')->count())->toBeGreaterThan(5)
        ->and(EventOccurrence::query()->whereNotNull('practical_details')->count())->toBeGreaterThan(10)
        ->and(DB::table('occurrence_views_daily')->count())->toBeGreaterThan(100)
        ->and(DB::table('event_views_daily')->count())->toBeGreaterThan(50)
        ->and(DB::table('sponsorship_daily_stats')->count())->toBeGreaterThan(20);
    // Le campagne dimostrative sono concluse: nessuna compare sul sito con «Sponsorizzato».
    expect(DB::table('sponsorships')->where('advertiser_name', 'Campagna dimostrativa')->count())->toBeGreaterThan(0)
        ->and(Sponsorship::query()->visible()->where('advertiser_name', 'Campagna dimostrativa')->exists())->toBeFalse();

    // Nessuno riceve niente: né mail, né avvisi in app — compresi quelli della biglietteria.
    Mail::assertNothingOutgoing();
    expect(DB::table('notifications')->count())->toBe(0)
        ->and(DB::table('bookings')->count())->toBeGreaterThan(0);
});

it('is idempotent on a second run', function (): void {
    $this->artisan('demo:quindicina', ['city' => $this->city->slug, '--riusati' => 4])->assertSuccessful();
    $before = fortnightCounts();

    $this->artisan('demo:quindicina', ['city' => $this->city->slug, '--riusati' => 4])->assertSuccessful();

    expect(fortnightCounts())->toBe($before);
});

it('purges what it added and leaves the history of the reused events in place', function (): void {
    $baseline = ['occurrences' => EventOccurrence::query()->count(), 'events' => Event::query()->count()];
    $this->artisan('demo:quindicina', ['city' => $this->city->slug, '--riusati' => 4])->assertSuccessful();

    $this->artisan('demo:quindicina', ['city' => $this->city->slug, '--purge' => true])->assertSuccessful();

    $extra = FortnightDemoCommand::emails(FortnightDemoCommand::catalog());
    expect(Event::query()->count())->toBe($baseline['events'])
        ->and(EventOccurrence::query()->count())->toBe($baseline['occurrences'])
        ->and(User::withTrashed()->whereIn('email', $extra)->count())->toBe(0)
        ->and(EventPoll::query()->count())->toBe(0)
        ->and(RideOffer::query()->where('departure_at', '>', now())->count())->toBe(0)
        ->and(EventComment::query()->count())->toBe(0)
        ->and(DB::table('sponsorships')->where('advertiser_name', 'Campagna dimostrativa')->count())->toBe(0)
        ->and(DB::table('event_views_daily')->count())->toBe(0)
        ->and(Setting::query()->where('key', FortnightDemoCommand::SETTING)->exists())->toBeFalse();
    // Le persone della vetrina restano: sono di demo:showcase.
    expect(User::query()->whereIn('email', ShowcaseDemoCommand::emails(ShowcaseDemoCommand::catalog()))->count())
        ->toBe(count(ShowcaseDemoCommand::catalog()['people']));
});
