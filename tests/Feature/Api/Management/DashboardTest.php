<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\UserRole;
use App\Enums\VenueRole;
use App\Enums\VenueStatus;
use App\Filament\Venue\Pages\Dashboard;
use App\Filament\Venue\Widgets\MissingLocationWidget;
use App\Filament\Venue\Widgets\RecentViewsWidget;
use App\Filament\Venue\Widgets\TodayScheduleWidget;
use App\Filament\Venue\Widgets\UpcomingEventsWidget;
use App\Filament\Venue\Widgets\VenueOverviewWidget;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\VenueIsolationScenario;

/*
 * Il riepilogo dall'app di gestione (P11): gli stessi riquadri della prima
 * pagina dei pannelli, con gli stessi numeri sugli stessi dati, e il
 * programma della settimana in PDF del locale.
 *
 * Giovedì 10 settembre 2026, ore 18 a Padova. Il locale A ha: l'evento
 * pubblicato stasera alle 21 e domani (la data dello scenario), un secondo
 * evento fra tre giorni, una bozza; il locale B un evento oggi che non deve
 * comparire mai.
 */
beforeEach(function (): void {
    $this->s = VenueIsolationScenario::make();
    freezeLocal($this->s->city, '2026-09-10 18:00');
    $this->venueUrl = '/api/v1/management/venues/'.$this->s->venueA->id;

    $this->tonight = p11Date($this->s->publishedEventA, '2026-09-10 19:00:00');
    $this->later = Event::factory()->create([
        'city_id' => $this->s->city->id, 'category_id' => $this->s->category->id, 'venue_id' => $this->s->venueA->id,
        'title' => 'Jazz in cortile', 'status' => EventStatus::Published, 'published_at' => CarbonImmutable::now('UTC'),
    ]);
    $this->laterDate = p11Date($this->later, '2026-09-13 19:00:00');
    p11Date($this->s->publishedEventB, '2026-09-10 19:00:00');

    $this->organizer = Organizer::create(['name' => 'Associazione Musica Aperta', 'city_id' => $this->s->city->id, 'owner_id' => User::factory()->create()->id, 'is_active' => true]);
    $this->member = User::factory()->create();
    $this->organizer->users()->attach($this->member);
    $this->organizerUrl = '/api/v1/management/organizers/'.$this->organizer->id;
});

function p11Date(Event $event, string $startsAtUtc): EventOccurrence
{
    return EventOccurrence::factory()->create([
        'event_id' => $event->getKey(),
        'starts_at' => CarbonImmutable::parse($startsAtUtc, 'UTC'),
        'ends_at' => null,
        'doors_at' => null,
    ]);
}

/** @return list<int> */
function p11Ids(array $cards, string $path = 'event.id'): array
{
    return array_map(fn (array $card): int => (int) data_get($card, $path), $cards);
}

// ------------------------------------------------------------ chi vede cosa

it('apre il riepilogo a chi gestisce il soggetto, a nessun altro', function (): void {
    foreach ([$this->s->ownerA, $this->s->editorA] as $user) {
        Sanctum::actingAs($user);
        $this->getJson($this->venueUrl.'/dashboard')->assertOk()->assertJsonPath('data.subject.name', 'Circolo Aurora');
    }
    foreach ([$this->organizer->owner, $this->member] as $user) {
        Sanctum::actingAs($user);
        $this->getJson($this->organizerUrl.'/dashboard')->assertOk()->assertJsonPath('data.subject.type', 'organizer');
    }

    // Personale d'ingresso, estraneo, referente di un altro locale: il soggetto non esiste per loro.
    $staff = User::factory()->create();
    $this->s->occurrenceA->checkinStaff()->attach($staff);
    foreach ([$staff, $this->s->plainUser, $this->s->ownerB] as $user) {
        Sanctum::actingAs($user);
        $this->getJson($this->venueUrl.'/dashboard')->assertNotFound();
        $this->getJson($this->venueUrl.'/dashboard/programme')->assertNotFound();
        $this->getJson($this->organizerUrl.'/dashboard')->assertNotFound();
    }

    // Un locale sospeso non si apre, come nel pannello.
    $suspended = Venue::factory()->suspended()->create(['city_id' => $this->s->city->id]);
    $owner = User::factory()->create();
    $owner->assignRole(UserRole::VenueOwner->value);
    $owner->venues()->attach($suspended, ['role' => VenueRole::Owner->value, 'invited_at' => CarbonImmutable::now(), 'accepted_at' => CarbonImmutable::now()]);
    Sanctum::actingAs($owner);
    $this->getJson('/api/v1/management/venues/'.$suspended->id.'/dashboard')->assertNotFound();
});

// ------------------------------------------------------------ i riquadri

it('conta oggi, prossimi e bozze del solo locale, come il cartellone del pannello', function (): void {
    Sanctum::actingAs($this->s->ownerA);

    $this->getJson($this->venueUrl.'/dashboard')->assertOk()
        ->assertJsonPath('data.timezone', 'Europe/Rome')
        ->assertJsonPath('data.counts', ['today' => 1, 'upcoming' => 3, 'drafts' => 1]);
});

it('mette in «oggi» le serate di stasera e in «prossimi» un evento per riga, oggi escluso', function (): void {
    Sanctum::actingAs($this->s->ownerA);
    $data = $this->getJson($this->venueUrl.'/dashboard')->assertOk()->json('data');

    expect(p11Ids($data['today'], 'date.id'))->toBe([$this->tonight->id])
        ->and($data['today'][0]['event']['title'])->toBe('Concerto di musica popolare')
        ->and($data['today'][0]['date']['starts_at'])->toBe('2026-09-10T19:00:00+00:00')
        ->and($data['today'][0]['date']['scheduled'])->toBeTrue()
        ->and($data['today'][0]['event'])->toHaveKey('poster')
        // La prima data dell'evento di stasera è oggi: il pannello lo lascia nel riquadro «Oggi» e non lo ripete.
        ->and(p11Ids($data['upcoming']))->toBe([$this->later->id])
        ->and(p11Ids($data['upcoming'], 'date.id'))->toBe([$this->laterDate->id]);
});

it('dà alle card gli stessi collegamenti del pannello, con gli stessi permessi', function (): void {
    Sanctum::actingAs($this->s->ownerA);
    $today = $this->getJson($this->venueUrl.'/dashboard')->json('data.today.0.links');

    expect($today)->toBe([
        'edit' => true,
        // La biglietteria della prossima data: quella di stasera.
        'tickets_occurrence_id' => $this->tonight->id,
        'statistics' => true,
        'social' => true,
        'sponsor' => true,
    ]);

    // Il collaboratore non gestisce le prenotazioni, come nella scheda dell'evento del pannello.
    Sanctum::actingAs($this->s->editorA);
    expect($this->getJson($this->venueUrl.'/dashboard')->json('data.today.0.links.tickets_occurrence_id'))->toBeNull();
});

it('elenca le tre schede più viste degli ultimi sette giorni, con le visite', function (): void {
    DB::table('event_views_daily')->insert([
        ['event_id' => $this->s->publishedEventA->id, 'date' => '2026-09-10', 'views' => 5],
        ['event_id' => $this->s->publishedEventA->id, 'date' => '2026-09-04', 'views' => 2],
        // Otto giorni fa: fuori dalla settimana.
        ['event_id' => $this->later->id, 'date' => '2026-09-02', 'views' => 50],
        ['event_id' => $this->s->draftEventA->id, 'date' => '2026-09-09', 'views' => 9],
        // L'altro locale non entra mai.
        ['event_id' => $this->s->publishedEventB->id, 'date' => '2026-09-10', 'views' => 100],
    ]);
    Sanctum::actingAs($this->s->ownerA);
    $recent = $this->getJson($this->venueUrl.'/dashboard')->assertOk()->json('data.recent_views');

    expect($recent['days'])->toBe(7)
        ->and(p11Ids($recent['items']))->toBe([$this->s->draftEventA->id, $this->s->publishedEventA->id])
        ->and(array_column($recent['items'], 'views'))->toBe([9, 7])
        ->and($recent['items'][0]['date'])->toBeNull()
        ->and($recent['items'][0]['links']['sponsor'])->toBeFalse();
});

it('risponde come i riquadri del pannello sugli stessi dati', function (): void {
    DB::table('event_views_daily')->insert(['event_id' => $this->later->id, 'date' => '2026-09-09', 'views' => 3]);
    $this->s->venueA->update(['lat' => $this->s->city->center_lat, 'lng' => $this->s->city->center_lng]);

    $this->actingAs($this->s->ownerA);
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->s->venueA);
    $this->get(Dashboard::getUrl())->assertOk();
    $numbers = collect((new VenueOverviewWidget)->numbers())->pluck('value', 'key')->map(fn (string $value): int => (int) $value)->all();
    $panel = [
        'counts' => $numbers,
        'today' => (new TodayScheduleWidget)->schedule()->pluck('id')->all(),
        'upcoming' => (new UpcomingEventsWidget)->upcoming()->pluck('id')->all(),
        'recent' => collect((new RecentViewsWidget)->cards())->pluck('title')->all(),
        'missing' => MissingLocationWidget::canView(),
    ];

    Sanctum::actingAs($this->s->ownerA);
    $data = $this->getJson($this->venueUrl.'/dashboard')->assertOk()->json('data');

    expect($data['counts'])->toBe($panel['counts'])
        ->and(p11Ids($data['today'], 'date.id'))->toBe($panel['today'])
        ->and(p11Ids($data['upcoming'], 'date.id'))->toBe($panel['upcoming'])
        ->and(array_map(fn (array $card): string => $card['event']['title'], $data['recent_views']['items']))->toBe($panel['recent'])
        ->and($data['missing_location'])->toBe($panel['missing'])->toBeTrue();
});

it('avvisa il locale che sta ancora sul centro della città, e tace appena il punto si sposta', function (): void {
    Sanctum::actingAs($this->s->ownerA);
    $this->s->venueA->update(['lat' => $this->s->city->center_lat, 'lng' => $this->s->city->center_lng]);
    $this->getJson($this->venueUrl.'/dashboard')->assertJsonPath('data.missing_location', true);

    $this->s->venueA->update(['lat' => (float) $this->s->city->center_lat + 0.001]);
    $this->getJson($this->venueUrl.'/dashboard')->assertJsonPath('data.missing_location', false);
});

// ------------------------------------------------------------ organizzazione

it('dà all’organizzazione le date dei suoi eventi e di quelli co-organizzati, e le bozze solo dei suoi', function (): void {
    $this->later->update(['organizer_id' => $this->organizer->id]);
    $this->s->draftEventA->update(['organizer_id' => $this->organizer->id]);
    // Co-organizzato: le date contano, le statistiche dell'evento no (sono dell'organizzatore principale).
    $this->s->publishedEventA->organizers()->attach($this->organizer);
    Sanctum::actingAs($this->member);

    $data = $this->getJson($this->organizerUrl.'/dashboard')->assertOk()->json('data');

    expect($data['counts'])->toBe(['today' => 1, 'upcoming' => 3, 'drafts' => 1])
        ->and($data['missing_location'])->toBeFalse()
        ->and($data['programme'])->toBeNull()
        ->and(p11Ids($data['today']))->toBe([$this->s->publishedEventA->id])
        ->and($data['today'][0]['links']['statistics'])->toBeFalse()
        ->and($data['today'][0]['links']['social'])->toBeFalse()
        ->and(p11Ids($data['upcoming']))->toBe([$this->later->id])
        ->and($data['upcoming'][0]['links']['statistics'])->toBeTrue();

    // Il programma in PDF è solo del locale.
    $this->get($this->organizerUrl.'/dashboard/programme')->assertNotFound();
});

// ------------------------------------------------------------ programma in PDF

it('stampa il programma di questa settimana e della prossima, come i pulsanti del pannello', function (): void {
    Sanctum::actingAs($this->s->editorA);

    $this->getJson($this->venueUrl.'/dashboard')->assertJsonPath('data.programme', [
        'available' => true,
        'this_week' => '2026-09-07',
        'next_week' => '2026-09-14',
    ]);

    $this->get($this->venueUrl.'/dashboard/programme')->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'attachment; filename="circolo-aurora-programma-2026-09-07.pdf"');
    $this->get($this->venueUrl.'/dashboard/programme?week=2026-09-16')->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="circolo-aurora-programma-2026-09-14.pdf"');
    $this->getJson($this->venueUrl.'/dashboard/programme?week=domani')->assertUnprocessable();
});

it('non stampa il programma di un locale non ancora approvato, come la pagina pubblica', function (): void {
    $this->s->venueA->update(['status' => VenueStatus::Pending]);
    Sanctum::actingAs($this->s->ownerA);

    // Il locale in attesa si gestisce già (pannello e app), ma il suo programma non esiste ancora.
    $this->getJson($this->venueUrl.'/dashboard')->assertOk()->assertJsonPath('data.programme.available', false);
    $this->get($this->venueUrl.'/dashboard/programme')->assertNotFound();
});
