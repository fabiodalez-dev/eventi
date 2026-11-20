<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\PromotionMode;
use App\Enums\SponsorshipPlacement;
use App\Enums\SponsorshipStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Pages\SponsorshipAnalytics as AdminAnalytics;
use App\Filament\Organizer\Pages\Sponsorships as OrganizerSponsorships;
use App\Filament\Venue\Pages\SponsorshipAnalytics as VenueAnalytics;
use App\Filament\Venue\Pages\Sponsorships as VenueSponsorships;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Organizer;
use App\Models\Sponsorship;
use App\Models\SponsorshipDailyStat;
use App\Models\SponsorshipGrant;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\SponsorshipPacingAlert;
use App\Notifications\SponsorshipWeeklyReport;
use App\Services\Carpool\UnifiedNotifications;
use App\Services\Sponsorship\Delivery\PacingState;
use App\Services\Sponsorship\SponsorshipProgress;
use App\Services\Sponsorship\UnitProgress;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * L'avanzamento delle sponsorizzazioni: la barra e la frase che vedono il
 * gestore e la redazione, il riepilogo del lunedì e l'avviso del mattino.
 *
 * **I numeri a mano.** Senza dati di traffico la previsione è la curva di
 * partenza su 200 visualizzazioni al giorno per la cima dell'elenco: da lunedì
 * a giovedì 186,67 al giorno, venerdì e sabato 233,33, domenica 186,67. Il
 * periodo va da lunedì 16 a mercoledì 25 novembre (dieci giorni, 1.960
 * visualizzazioni previste) e «adesso» è venerdì 20 alle 0:00: sono passati
 * quattro giorni feriali, 746,67 visualizzazioni previste, cioè 4 ÷ 10,5 del
 * periodo. Con un obiettivo di 1.000 se ne attendono 380,95, e ne restano
 * previste 1.213,33 (1,625 volte quelle passate).
 */

beforeEach(function (): void {
    config([
        'sponsorship.default_daily_views.list_top' => 200,
        'sponsorship.quota_max' => 0.8,
        'sponsorship.pacing_thresholds' => ['late' => 0.7, 'on_track' => 0.9, 'ahead' => 1.1],
        'sponsorship.forecast_min_days' => 14,
        'sponsorship.min_impressions_for_ctr' => 100,
    ]);

    (new RolesAndPermissionsSeeder)->run();
    $this->city = testCity();
    $this->category = testCategory();
    $this->now = freezeLocal($this->city, '2026-11-20 00:00');
    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Circolo Aurora']);
    $this->owner = User::factory()->create();
    $this->owner->assignRole(UserRole::VenueOwner->value);
    $this->owner->venues()->attach($this->venue, ['role' => 'owner']);
    $this->admin = User::factory()->create(['name' => 'Redazione']);
    $this->admin->assignRole(UserRole::Admin->value);
});

afterEach(function (): void {
    Filament::setTenant(null);
    Filament::setCurrentPanel('admin');
});

/** Un evento pubblicato del locale con una data all'ora locale indicata. */
function p5Event(Venue $venue, string $title, string $localDate, ?Organizer $organizer = null): Event
{
    $event = Event::factory()->create([
        'venue_id' => $venue->id, 'city_id' => $venue->city_id, 'category_id' => test()->category->id,
        'organizer_id' => $organizer?->id, 'status' => EventStatus::Published, 'published_at' => now()->subMonth(), 'title' => $title,
    ]);
    $start = CarbonImmutable::parse($localDate, 'Europe/Rome')->utc();
    EventOccurrence::factory()->create(['event_id' => $event->id, 'starts_at' => $start, 'ends_at' => $start->addHours(2)]);

    return $event;
}

/**
 * Un'abilitazione pagata dal 16 al 25 novembre, ora di Padova.
 *
 * @param  array<string, mixed>  $attributes
 */
function p5Grant(Venue|Organizer $sponsor, array $attributes = []): SponsorshipGrant
{
    $factory = SponsorshipGrant::factory();
    $factory = $sponsor instanceof Venue ? $factory->state(['venue_id' => $sponsor->id]) : $factory->forOrganizer($sponsor);

    return $factory->create([
        'starts_at' => CarbonImmutable::parse('2026-11-16 00:00', 'Europe/Rome')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-11-25 23:59:59', 'Europe/Rome')->utc(),
        'paid_at' => CarbonImmutable::parse('2026-11-15 10:00', 'Europe/Rome')->utc(),
        ...$attributes,
    ]);
}

/**
 * Una riga evento dell'abilitazione con le sue visualizzazioni.
 *
 * @param  array<string, mixed>  $attributes
 */
function p5Row(SponsorshipGrant $grant, Event $event, int $impressions, int $clicks = 0, array $attributes = []): Sponsorship
{
    return Sponsorship::factory()->forGrant($grant)->create([
        'event_id' => $event->id, 'city_id' => $event->city_id, 'impressions' => $impressions, 'clicks' => $clicks,
        'advertiser_name' => $grant->sponsorName(), 'advertiser_email' => null, ...$attributes,
    ]);
}

/** Abilitazione a visualizzazioni con un evento dopo la fine del periodo e le consegnate indicate. */
function p5GoalGrant(int $delivered, int $goal = 1000, ?Venue $venue = null): SponsorshipGrant
{
    $venue ??= test()->venue;
    $grant = p5Grant($venue, ['impressions_goal' => $goal]);
    p5Row($grant, p5Event($venue, 'Concerto del circolo '.$grant->id, '2026-11-28 21:00'), $delivered);

    return $grant;
}

function p5Progress(SponsorshipGrant $grant): UnitProgress
{
    return app(SponsorshipProgress::class)->forGrant($grant->fresh());
}

function p5VenuePage(?Venue $venue = null, ?User $user = null): void
{
    $venue ??= test()->venue;
    test()->actingAs($user ?? test()->owner)->get('/gestione/'.$venue->slug.'/sponsorizzazioni')->assertOk();
    Filament::setCurrentPanel('venue');
    Filament::setTenant($venue);
}

/** @return list<string> */
function p5MailLines(MailMessage $message): array
{
    return array_map(static fn ($line): string => (string) $line, array_merge($message->introLines, $message->outroLines));
}

/* ─────────────────  I numeri dell'avanzamento  ───────────────── */

it('somma le visualizzazioni di tutte le righe dell\'abilitazione, anche di quelle tolte', function (): void {
    $grant = p5Grant($this->venue, ['impressions_goal' => 1000]);
    p5Row($grant, p5Event($this->venue, 'Primo', '2026-11-28 21:00'), 300, 4);
    p5Row($grant, p5Event($this->venue, 'Secondo', '2026-11-27 21:00'), 81, 1)->delete();

    $progress = p5Progress($grant);

    expect($progress->key)->toBe('g:'.$grant->id)
        ->and($progress->delivered)->toBe(381)
        ->and($progress->clicks)->toBe(5)
        ->and($progress->delivered)->toBe($grant->fresh()->deliveredImpressions())
        ->and($progress->numbers())->toBe('381 di 1.000 visualizzazioni')
        ->and($progress->percent())->toBe(38.1);
});

it('calcola l\'atteso sul traffico previsto e non sul calendario', function (): void {
    $progress = p5Progress(p5GoalGrant(381));

    /* Quattro giorni feriali su dieci giorni che valgono 10,5: non il 40%. */
    expect($progress->expected)->toEqualWithDelta(1000 * 4 / 10.5, 0.01)
        ->and($progress->expectedPercent())->toEqualWithDelta(38.095, 0.01)
        ->and($progress->forecast->isProvisional())->toBeTrue()
        ->and($progress->quota)->toEqualWithDelta(619 / (1960 - 746.6667), 0.001)
        ->and($progress->daysLeft())->toBe(5);
});

it('dà lo stato giusto a ogni ritmo di consegna', function (int $delivered, PacingState $state): void {
    expect(p5Progress(p5GoalGrant($delivered))->state)->toBe($state);
})->with([
    'in linea' => [381, PacingState::OnTrack],
    'in leggero ritardo' => [300, PacingState::SlightlyBehind],
    'in ritardo' => [200, PacingState::Behind],
    'in anticipo' => [450, PacingState::Ahead],
    'a rischio: non ci sta più nel traffico che resta' => [10, PacingState::AtRisk],
    'obiettivo raggiunto' => [1000, PacingState::Completed],
]);

it('scrive la frase con la data di arrivo al ritmo attuale', function (): void {
    $inLinea = p5Progress(p5GoalGrant(381));
    $avanti = p5Progress(p5GoalGrant(450));

    expect($inLinea->phrase())->toBe('381 di 1.000 visualizzazioni, in linea: al ritmo attuale arrivi all\'obiettivo mercoledì 25 novembre.')
        /* 550 mancanti al 60% del traffico: venerdì, sabato, domenica e lunedì non bastano, martedì sì. */
        ->and($avanti->phrase())->toBe('450 di 1.000 visualizzazioni, in anticipo: al ritmo attuale arrivi all\'obiettivo martedì 24 novembre.')
        ->and($avanti->projectedDate?->isoFormat('YYYY-MM-DD'))->toBe('2026-11-24');
});

it('dice dove arriva quando al ritmo attuale non arriva all\'obiettivo', function (): void {
    $progress = p5Progress(p5GoalGrant(200));

    /* 200 su 746,67 previste, applicato alle 1.213,33 che restano: 325 in più. */
    expect($progress->projectedTotal)->toBe(525)
        ->and($progress->projectedDate)->toBeNull()
        ->and($progress->phrase())->toBe('200 di 1.000 visualizzazioni, in ritardo: al ritmo attuale arrivi a circa 525 entro mercoledì 25 novembre.');
});

it('dice quando l\'obiettivo non ci sta più nel traffico che resta', function (): void {
    expect(p5Progress(p5GoalGrant(10))->phrase())
        ->toBe('10 di 1.000 visualizzazioni, a rischio: il traffico che resta fino a mercoledì 25 novembre non basta per arrivare all\'obiettivo.');
});

it('non azzarda una data prima di aver visto traffico', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 05:00', 'Europe/Rome'));
    $progress = p5Progress(p5GoalGrant(3));

    expect($progress->projectedDate)->toBeNull()->and($progress->projectedTotal)->toBeNull()
        ->and($progress->phrase())->toContain('è ancora presto per stimare quando arrivi all\'obiettivo');
});

it('chiude con l\'obiettivo raggiunto o sotto l\'obiettivo', function (): void {
    $fatta = p5GoalGrant(1000);
    $mancata = p5GoalGrant(900);

    expect(p5Progress($fatta)->phrase())->toBe('1.000 di 1.000 visualizzazioni: obiettivo raggiunto.')
        ->and(p5Progress($fatta)->percent())->toBe(100.0);

    $this->travelTo(CarbonImmutable::parse('2026-11-26 09:00', 'Europe/Rome'));

    expect(p5Progress($mancata)->state)->toBe(PacingState::Missed)
        ->and(p5Progress($mancata)->phrase())->toBe('900 di 1.000 visualizzazioni: il periodo si è chiuso sotto l\'obiettivo.')
        ->and(p5Progress($mancata)->expectedPercent())->toBeNull();
});

it('legge le scadenze alla fine vera, cioè all\'ultima data coperta', function (): void {
    $grant = p5Grant($this->venue, ['impressions_goal' => 1000]);
    p5Row($grant, p5Event($this->venue, 'Ultima serata', '2026-11-22 21:00'), 10);

    $progress = p5Progress($grant);

    /* L'evento di domenica 22 finisce alle 23: il piano lo tiene in cima
       all'elenco fino a quel momento (`UnitReach`), e dopo la
       sponsorizzazione non può più consegnare, anche se il periodo va al 25. */
    expect($progress->trueEnd->isoFormat('YYYY-MM-DD HH:mm'))->toBe('2026-11-22 23:00')
        ->and($progress->end->isoFormat('YYYY-MM-DD'))->toBe('2026-11-25')
        ->and($progress->phrase())->toContain('fino a domenica 22 novembre');
});

it('dice che la consegna è ferma quando non ci sono più date in arrivo', function (): void {
    $grant = p5Grant($this->venue, ['impressions_goal' => 1000]);
    p5Row($grant, p5Event($this->venue, 'Già passato', '2026-11-18 21:00'), 120);

    $progress = p5Progress($grant);

    expect($progress->idle)->toBeTrue()
        ->and($progress->trueEnd->isoFormat('YYYY-MM-DD'))->toBe('2026-11-25')
        ->and($progress->phrase())->toBe('120 di 1.000 visualizzazioni: nessun evento con date in arrivo, la consegna riprende con il prossimo evento pubblicato.');
});

it('annuncia una sponsorizzazione che deve ancora cominciare', function (): void {
    $grant = p5GoalGrant(0);
    $grant->update(['paid_at' => CarbonImmutable::parse('2026-11-10 10:00', 'Europe/Rome')->utc()]);
    $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00', 'Europe/Rome'));
    $progress = p5Progress($grant);

    expect($progress->started())->toBeFalse()
        ->and($progress->badge())->toBe('Programmata')
        ->and($progress->percent())->toBe(0.0)
        ->and($progress->phrase())->toBe('Comincia lunedì 16 novembre: 1.000 visualizzazioni da consegnare entro mercoledì 25 novembre.');
});

it('dice che non consegna finché il pagamento non è registrato', function (): void {
    $grant = p5GoalGrant(50);
    $grant->update(['paid_at' => null]);

    $progress = p5Progress($grant);

    expect($progress->halted)->toBe(UnitProgress::HALTED_UNPAID)
        ->and($progress->badge())->toBe('In attesa di pagamento')
        ->and($progress->phrase())->toBe('50 di 1.000 visualizzazioni. Non compare sul sito finché il pagamento non risulta registrato.');
});

it('per il prodotto a periodo mostra il tempo passato e le visualizzazioni senza obiettivo', function (): void {
    $grant = p5Grant($this->venue);
    p5Row($grant, p5Event($this->venue, 'A periodo', '2026-11-28 21:00'), 1240, 31);

    $progress = p5Progress($grant);

    expect($progress->hasGoal())->toBeFalse()
        ->and($progress->state)->toBeNull()
        ->and($progress->badge())->toBe('A periodo')
        ->and($progress->percent())->toEqualWithDelta(40.0, 0.01)
        ->and($progress->expectedPercent())->toBeNull()
        ->and($progress->phrase())->toBe('1.240 visualizzazioni e 31 aperture finora, giorno 5 di 10: termina mercoledì 25 novembre.');
});

it('tratta la campagna singola della redazione come un\'unità a sé', function (): void {
    $event = p5Event($this->venue, 'Concerto singolo', '2026-11-28 21:00');
    $campaign = Sponsorship::factory()->goal(1000)->create([
        'event_id' => $event->id, 'city_id' => $this->city->id, 'impressions' => 381, 'clicks' => 7,
        'starts_at' => CarbonImmutable::parse('2026-11-16 00:00', 'Europe/Rome')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-11-25 23:59:59', 'Europe/Rome')->utc(),
    ]);

    $progress = app(SponsorshipProgress::class)->forRow($campaign);

    expect($progress->key)->toBe('s:'.$campaign->id)
        ->and($progress->state)->toBe(PacingState::OnTrack)
        ->and($progress->clicks)->toBe(7);

    $campaign->update(['status' => SponsorshipStatus::Paused]);

    expect(app(SponsorshipProgress::class)->forRow($campaign->fresh())->phrase())
        ->toBe('381 di 1.000 visualizzazioni. Sospesa: in questo momento non compare sul sito.');
});

it('per una riga di un\'abilitazione dà l\'avanzamento di tutta l\'abilitazione', function (): void {
    $grant = p5Grant($this->venue, ['impressions_goal' => 1000]);
    $row = p5Row($grant, p5Event($this->venue, 'Uno', '2026-11-28 21:00'), 200);
    p5Row($grant, p5Event($this->venue, 'Due', '2026-11-27 21:00'), 181);

    expect(app(SponsorshipProgress::class)->forRow($row)->delivered)->toBe(381);
});

it('legge tante abilitazioni con poche query, non una serie per unità', function (): void {
    $grants = collect(range(1, 6))->map(fn () => p5GoalGrant(100));
    $count = function (iterable $grants): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(SponsorshipProgress::class)->grants($grants);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $fresh = fn ($ids) => SponsorshipGrant::query()->whereKey($ids)->with(['venue', 'organizer'])->get();

    expect($count($fresh($grants->take(2)->pluck('id'))))->toBe($count($fresh($grants->pluck('id'))));
});

/* ─────────────────  La scheda del gestore  ───────────────── */

it('mostra una barra e una frase per ogni abilitazione del locale', function (): void {
    $goal = p5GoalGrant(381);
    $period = p5Grant($this->venue, ['placement' => SponsorshipPlacement::HomeCard, 'mode' => PromotionMode::Automatic]);
    p5Row($period, p5Event($this->venue, 'Serata in home', '2026-11-27 21:00'), 1240, 31, ['placement' => SponsorshipPlacement::HomeCard]);

    p5VenuePage();

    Livewire::test(VenueSponsorships::class)
        ->assertSee('data-sponsorship-progress="g:'.$goal->id.'"', false)
        ->assertSee('data-sponsorship-progress="g:'.$period->id.'"', false)
        ->assertSee('role="progressbar"', false)
        ->assertSee('381 di 1.000 visualizzazioni, in linea: al ritmo attuale arrivi all&#039;obiettivo mercoledì 25 novembre.', false)
        ->assertSee('1.240 visualizzazioni e 31 aperture finora, giorno 5 di 10')
        ->assertSee(__('sponsorship-panel.units.scope_all'))
        ->assertSee(__('sponsorship-panel.units.measures'))
        ->assertSee('data-sponsorship-expected', false);
});

it('non mostra a un gestore i numeri di un altro', function (): void {
    p5GoalGrant(381);
    $other = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Teatro Altrove']);
    $foreign = p5GoalGrant(777, 2000, $other);
    Sponsorship::factory()->goal(5000)->create([
        'event_id' => p5Event($other, 'Concerto altrui', '2026-11-28 21:00')->id, 'city_id' => $this->city->id, 'impressions' => 4321,
    ]);

    p5VenuePage();

    Livewire::test(VenueSponsorships::class)
        ->assertSee('381 di 1.000 visualizzazioni')
        ->assertDontSee('777 di 2.000')
        ->assertDontSee('data-sponsorship-grant="'.$foreign->id.'"', false)
        ->assertDontSee('4.321')
        ->assertDontSee('Concerto altrui');

    /* E i totali sono quelli del locale soltanto. */
    $page = Livewire::test(VenueSponsorships::class)->instance();
    expect($page->totals())->toBe(['impressions' => 381, 'clicks' => 0]);
});

it('mostra gli eventi della scheda con i loro numeri, e gli altri a richiesta', function (): void {
    $grant = p5Grant($this->venue, ['impressions_goal' => 1000, 'mode' => PromotionMode::Automatic]);
    foreach (range(1, 7) as $i) {
        p5Row($grant, p5Event($this->venue, 'Serata numero '.$i, '2026-11-2'.$i.' 21:00'), 10 * $i, $i);
    }
    $gone = p5Row($grant, p5Event($this->venue, 'Riga tolta', '2026-11-28 21:00'), 50);
    $gone->delete();

    p5VenuePage();

    $page = Livewire::test(VenueSponsorships::class)
        ->assertSee('330 di 1.000 visualizzazioni')
        ->assertSee('Serata numero 7')
        ->assertSee('70 visualizzazioni · 7 aperture')
        ->assertSee('21% delle visualizzazioni')
        ->assertSee('Mostra gli altri 2 eventi');
    $rows = fn (): int => substr_count($page->html(), 'data-sponsorship-row="');

    /* Le cinque più viste; la riga nel cestino conta nel totale ma non si elenca. */
    expect($rows())->toBe(5)
        ->and($page->html())->not->toContain('data-sponsorship-row="'.$gone->id.'"');

    $page->call('toggleGrantEvents', $grant->id)->assertSee(__('sponsorship-panel.units.show_less'));
    expect($rows())->toBe(7);

    $page->call('toggleGrantEvents', $grant->id)->assertSee('Mostra gli altri 2 eventi');
    expect($rows())->toBe(5);
});

it('lascia fermare un evento scelto dalla sua scheda', function (): void {
    $grant = p5Grant($this->venue, ['impressions_goal' => 1000]);
    $row = p5Row($grant, p5Event($this->venue, 'Da fermare', '2026-11-28 21:00'), 12);

    p5VenuePage();

    Livewire::test(VenueSponsorships::class)
        ->assertSee(__('promotions.stop'))
        ->call('stop', $row->id)
        ->assertSee(__('sponsorship-panel.units.event_stopped'));

    expect($row->fresh()->status)->toBe(SponsorshipStatus::Paused);
});

it('mette le campagne della redazione a parte, ognuna con il suo avanzamento', function (): void {
    p5GoalGrant(381);
    $single = Sponsorship::factory()->goal(500)->create([
        'event_id' => p5Event($this->venue, 'Scelta della redazione', '2026-11-28 21:00')->id, 'city_id' => $this->city->id,
        'impressions' => 120, 'starts_at' => CarbonImmutable::parse('2026-11-16 00:00', 'Europe/Rome')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-11-25 23:59:59', 'Europe/Rome')->utc(),
    ]);

    p5VenuePage();

    Livewire::test(VenueSponsorships::class)
        ->assertSee(__('sponsorship-panel.units.singles_title'))
        ->assertSee('data-sponsorship-campaign="'.$single->id.'"', false)
        ->assertSee('data-sponsorship-progress="s:'.$single->id.'"', false)
        ->assertSee('120 di 500 visualizzazioni')
        ->assertSee('Scelta della redazione');
});

it('dice che non c\'è ancora niente quando il locale non ha sponsorizzazioni', function (): void {
    p5VenuePage();

    Livewire::test(VenueSponsorships::class)
        ->assertSee(__('sponsorships.venue.none'))
        ->assertDontSee('data-sponsorship-grants', false)
        ->assertDontSee(__('sponsorship-panel.units.singles_title'));
});

it('mostra l\'avanzamento anche nel pannello dell\'organizzatore, senza i numeri del locale', function (): void {
    $organizerOwner = User::factory()->create();
    $organizer = Organizer::create(['name' => 'Collettivo Portici', 'city_id' => $this->city->id, 'owner_id' => $organizerOwner->id, 'is_active' => true]);
    $grant = p5Grant($organizer, ['impressions_goal' => 800]);
    p5Row($grant, p5Event($this->venue, 'Serata del collettivo', '2026-11-28 21:00', $organizer), 150);
    p5GoalGrant(381);

    $this->actingAs($organizerOwner)->get('/organizza/'.$organizer->slug.'/sponsorizzazioni')->assertOk();
    Filament::setCurrentPanel('organizer');
    Filament::setTenant($organizer);

    Livewire::test(OrganizerSponsorships::class)
        ->assertSee('data-sponsorship-progress="g:'.$grant->id.'"', false)
        ->assertSee('150 di 800 visualizzazioni')
        ->assertSee('Circolo Aurora')
        ->assertDontSee('381 di 1.000');
});

it('risponde alla pagina vera del pannello senza errori', function (): void {
    p5GoalGrant(381);

    $this->actingAs($this->owner)->get('/gestione/'.$this->venue->slug.'/sponsorizzazioni')
        ->assertOk()
        ->assertSee('381 di 1.000 visualizzazioni')
        ->assertDontSee('sponsorship-panel.');
});

/* ─────────────────  Statistiche  ───────────────── */

it('non mostra il tasso di aperture sotto le cento visualizzazioni', function (): void {
    $campaign = Sponsorship::factory()->create(['event_id' => p5Event($this->venue, 'Pochi numeri', '2026-11-28 21:00')->id, 'city_id' => $this->city->id]);
    SponsorshipDailyStat::query()->create(['sponsorship_id' => $campaign->id, 'day' => '2026-11-19', 'impressions' => 40, 'clicks' => 4]);

    $this->actingAs($this->admin)->get('/admin/statistiche-sponsorizzazioni')->assertOk();
    Filament::setCurrentPanel('admin');

    Livewire::test(AdminAnalytics::class)
        ->assertSee('Da 100 visualizzazioni')
        ->assertSee(__('sponsorship-panel.analytics.rate_threshold', ['min' => '100']))
        ->assertDontSee('10,00%')
        ->assertDontSee('In media una persona su');

    SponsorshipDailyStat::query()->where('sponsorship_id', $campaign->id)->update(['impressions' => 400]);

    Livewire::test(AdminAnalytics::class)
        ->assertSee('1,00%')
        ->assertDontSee(__('sponsorship-panel.analytics.rate_threshold', ['min' => '100']));
});

it('con una campagna scelta mostra l\'avanzamento della sua abilitazione e le consegnate contro le attese', function (): void {
    $grant = p5Grant($this->venue, ['impressions_goal' => 1000]);
    $row = p5Row($grant, p5Event($this->venue, 'Concerto misurato', '2026-11-28 21:00'), 300);
    p5Row($grant, p5Event($this->venue, 'Altro concerto', '2026-11-27 21:00'), 81);
    foreach (['2026-11-16' => 90, '2026-11-17' => 100, '2026-11-18' => 95, '2026-11-19' => 96] as $day => $views) {
        SponsorshipDailyStat::query()->create(['sponsorship_id' => $row->id, 'day' => $day, 'impressions' => $views, 'clicks' => 1]);
    }

    p5VenuePage();
    $page = Livewire::test(VenueAnalytics::class)->set('filters.campaign', $row->id)
        ->assertSee(__('sponsorship-panel.analytics.progress_title'))
        ->assertSee(__('sponsorship-panel.analytics.progress_lead_grant'))
        ->assertSee('381 di 1.000 visualizzazioni, in linea')
        ->assertSee('data-sponsorship-delivery', false);

    $delivery = $page->instance()->report()['delivery'];

    /* Dal lunedì 16 a venerdì 20 (oggi, appena cominciato): cinque giorni. */
    expect($delivery['days'])->toHaveCount(5)
        ->and(array_column($delivery['days'], 'delivered'))->toBe([90, 190, 285, 381, 381])
        ->and($delivery['days'][0]['expected'])->toBe(95)
        ->and($delivery['expected'])->toBe(381);
});

it('non mostra l\'avanzamento senza una campagna scelta, e non accetta quella di un altro locale', function (): void {
    p5GoalGrant(381);
    $other = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    $foreignRow = p5GoalGrant(777, 2000, $other)->sponsorships()->firstOrFail();

    p5VenuePage();

    Livewire::test(VenueAnalytics::class)
        ->assertDontSee(__('sponsorship-panel.analytics.progress_title'))
        ->set('filters.campaign', $foreignRow->id)
        ->assertForbidden();
});

/* ─────────────────  Il riepilogo del lunedì  ───────────────── */

it('mette nel riepilogo del lunedì obiettivo, consegnate e stato', function (): void {
    Notification::fake();
    $campaign = Sponsorship::factory()->goal(1000)->create([
        'event_id' => p5Event($this->venue, 'Concerto riepilogato', '2026-11-28 21:00')->id, 'city_id' => $this->city->id,
        'impressions' => 381, 'clicks' => 9, 'advertiser_name' => 'Etichetta Nord', 'advertiser_email' => 'etichetta@prova.test',
        'starts_at' => CarbonImmutable::parse('2026-11-16 00:00', 'Europe/Rome')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-11-25 23:59:59', 'Europe/Rome')->utc(),
    ]);
    SponsorshipDailyStat::query()->create(['sponsorship_id' => $campaign->id, 'day' => '2026-11-18', 'impressions' => 250, 'clicks' => 6]);

    $this->artisan('sponsorships:report')->assertExitCode(0);

    Notification::assertSentOnDemand(SponsorshipWeeklyReport::class, function (SponsorshipWeeklyReport $report): bool {
        $lines = implode("\n", p5MailLines($report->toMail((object) [])));

        return str_contains($lines, 'Obiettivo: 1.000 visualizzazioni.')
            && str_contains($lines, 'Consegnate finora: 381 (38%).')
            && str_contains($lines, 'Stato: in linea.')
            && str_contains($lines, 'al ritmo attuale arrivi all\'obiettivo mercoledì 25 novembre')
            && str_contains($lines, 'Sono 2,4 aperture ogni cento visualizzazioni.')
            && str_contains($lines, 'stima al ribasso');
    });
});

it('manda un riepilogo solo per tutta l\'abilitazione, con i numeri sommati', function (): void {
    Notification::fake();
    $grant = p5Grant($this->venue, ['impressions_goal' => 1000]);
    $first = p5Row($grant, p5Event($this->venue, 'Prima serata', '2026-11-28 21:00'), 300, 5, ['advertiser_email' => 'circolo@prova.test']);
    $second = p5Row($grant, p5Event($this->venue, 'Seconda serata', '2026-11-27 21:00'), 81, 1, ['advertiser_email' => 'Circolo@prova.test']);
    SponsorshipDailyStat::query()->create(['sponsorship_id' => $first->id, 'day' => '2026-11-18', 'impressions' => 30, 'clicks' => 1]);
    SponsorshipDailyStat::query()->create(['sponsorship_id' => $second->id, 'day' => '2026-11-18', 'impressions' => 20, 'clicks' => 0]);

    $this->artisan('sponsorships:report')->assertExitCode(0);

    Notification::assertSentOnDemandTimes(SponsorshipWeeklyReport::class, 1);
    Notification::assertSentOnDemand(SponsorshipWeeklyReport::class, function (SponsorshipWeeklyReport $report): bool {
        $mail = $report->toMail((object) []);
        $lines = implode("\n", p5MailLines($mail));

        return $mail->subject === 'Come sta andando: Circolo Aurora'
            && str_contains($lines, '**Visualizzazioni:** 50')
            && str_contains($lines, 'Da inizio campagna: 381 visualizzazioni e 6 aperture.')
            && str_contains($lines, 'Consegnate finora: 381 (38%).')
            /* 50 visualizzazioni: troppo poche per un tasso. */
            && str_contains($lines, 'Il tasso di aperture compare da 100 visualizzazioni in su')
            && ! str_contains($lines, 'aperture ogni cento visualizzazioni');
    });
});

it('dice nel riepilogo che una sponsorizzazione a periodo non ha un obiettivo', function (): void {
    $campaign = Sponsorship::factory()->create(['event_id' => p5Event($this->venue, 'A periodo', '2026-11-28 21:00')->id, 'city_id' => $this->city->id]);
    $progress = app(SponsorshipProgress::class)->forRow($campaign);

    $lines = p5MailLines((new SponsorshipWeeklyReport($campaign, $this->now->subWeek(), $this->now, 0, 0, $progress))->toMail((object) []));

    expect($lines)->toContain(__('sponsorship-panel.report.period'))
        ->and(implode(' ', $lines))->not->toContain('Obiettivo:');
});

/* ─────────────────  L'avviso del mattino alla redazione  ───────────────── */

it('avvisa la redazione delle sponsorizzazioni in ritardo, una volta al giorno e non di più', function (): void {
    $late = p5GoalGrant(200);
    p5GoalGrant(381);

    $this->artisan('sponsorships:pacing-check')->assertExitCode(0);
    $this->artisan('sponsorships:pacing-check')->assertExitCode(0);

    $alerts = DatabaseNotification::query()->where('type', SponsorshipPacingAlert::class)->get();

    expect($alerts)->toHaveCount(1)
        ->and($alerts->first()->notifiable_id)->toBe($this->admin->id)
        ->and($alerts->first()->data['title'])->toBe('Una sponsorizzazione da guardare')
        ->and($alerts->first()->data['body'])->toBe('In ritardo o a rischio sull\'obiettivo di visualizzazioni: Circolo Aurora · '.$late->placement->label().' (in ritardo).')
        ->and($alerts->first()->data['format'])->toBe('filament')
        ->and($alerts->first()->data['url'])->toEndWith('/admin/sponsorships');

    /* Il giorno dopo, se è ancora in ritardo, l'avviso torna. */
    $this->travelTo(CarbonImmutable::parse('2026-11-21 07:00', 'Europe/Rome'));
    $this->artisan('sponsorships:pacing-check')->assertExitCode(0);

    expect(DatabaseNotification::query()->where('type', SponsorshipPacingAlert::class)->count())->toBe(2);
});

it('non avvisa nessuno quando tutto è in linea, e non avvisa i gestori', function (): void {
    p5GoalGrant(381);
    p5Grant($this->venue);

    $this->artisan('sponsorships:pacing-check')->expectsOutputToContain(__('sponsorship-panel.pacing_check.none'))->assertExitCode(0);

    p5GoalGrant(10);
    $this->artisan('sponsorships:pacing-check')->assertExitCode(0);

    expect(DatabaseNotification::query()->where('type', SponsorshipPacingAlert::class)->pluck('notifiable_id')->all())->toBe([$this->admin->id])
        ->and($this->owner->notifications()->count())->toBe(0);
});

it('in prova elenca senza avvisare', function (): void {
    p5GoalGrant(10);

    $this->artisan('sponsorships:pacing-check', ['--dry-run' => true])
        ->expectsOutputToContain('(a rischio)')
        ->expectsOutputToContain(__('sponsorship-panel.pacing_check.dry_run'))
        ->assertExitCode(0);

    expect(DatabaseNotification::query()->count())->toBe(0);
});

it('non avvisa per le campagne chiuse, non pagate o a periodo', function (): void {
    p5GoalGrant(10)->update(['paid_at' => null]);
    p5Grant($this->venue, ['impressions_goal' => 1000, 'starts_at' => now()->subMonth(), 'ends_at' => now()->subDay()]);
    $period = p5Grant($this->venue);
    p5Row($period, p5Event($this->venue, 'Senza obiettivo', '2026-11-28 21:00'), 0);

    $this->artisan('sponsorships:pacing-check')->assertExitCode(0);

    expect(DatabaseNotification::query()->count())->toBe(0);
});

it('mette l\'avviso nella casella degli avvisi del sito con titolo e collegamento', function (): void {
    p5GoalGrant(200);
    p5GoalGrant(10);

    $this->artisan('sponsorships:pacing-check')->assertExitCode(0);

    $notification = $this->admin->notifications()->firstOrFail();
    $item = app(UnifiedNotifications::class)->presentation($notification);

    expect($item['title'])->toBe('2 sponsorizzazioni da guardare')
        ->and($item['body'])->toContain('(in ritardo)')
        ->and($item['body'])->toContain('(a rischio)')
        ->and($item['url'])->toEndWith('/admin/sponsorships');
});

it('avvisa anche per una campagna singola della redazione', function (): void {
    Sponsorship::factory()->goal(1000)->create([
        'event_id' => p5Event($this->venue, 'Campagna lenta', '2026-11-28 21:00')->id, 'city_id' => $this->city->id, 'impressions' => 150,
        'starts_at' => CarbonImmutable::parse('2026-11-16 00:00', 'Europe/Rome')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-11-25 23:59:59', 'Europe/Rome')->utc(),
    ]);

    $this->artisan('sponsorships:pacing-check')->assertExitCode(0);

    expect($this->admin->notifications()->firstOrFail()->data['body'])->toContain('Campagna lenta · ');
});

it('ha tutte le frasi in italiano, senza chiavi scoperte', function (): void {
    $chiavi = Arr::dot(require lang_path('it/sponsorship-panel.php'));

    foreach ($chiavi as $chiave => $testo) {
        expect($testo)->toBeString()->not->toBe('')->not->toContain('sponsorship-panel.');
    }

    foreach (PacingState::cases() as $state) {
        expect(__('sponsorship-panel.state.'.$state->value))->not->toStartWith('sponsorship-panel.')
            ->and(__('sponsorship-panel.state_inline.'.$state->value))->not->toStartWith('sponsorship-panel.');
    }
});

it('usa City e fuso della città anche per i committenti altrove', function (): void {
    $milano = City::factory()->create(['slug' => 'milano-p5', 'timezone' => 'Europe/Rome']);
    $venue = Venue::factory()->approved()->create(['city_id' => $milano->id]);
    $grant = p5Grant($venue, ['impressions_goal' => 1000]);
    $event = Event::factory()->create(['venue_id' => $venue->id, 'city_id' => $milano->id, 'status' => EventStatus::Published]);
    EventOccurrence::factory()->create(['event_id' => $event->id, 'starts_at' => now()->addDays(8), 'ends_at' => now()->addDays(8)->addHours(2)]);
    p5Row($grant, $event, 381);

    expect(p5Progress($grant)->timezone)->toBe('Europe/Rome')
        ->and(p5Progress($grant)->state)->toBe(PacingState::OnTrack);
});
