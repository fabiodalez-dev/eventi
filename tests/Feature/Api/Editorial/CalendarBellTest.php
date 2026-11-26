<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\Sponsorships\SponsorshipResource;
use App\Models\City;
use App\Models\User;
use App\Notifications\SponsorshipPacingAlert;
use App\Services\Editorial\EditorialCalendar;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Tests\Support\EditorialSession;
use Tests\Support\VenueIsolationScenario;

/*
 * R14 — il calendario della redazione (R-EV-27), la campanella (R-DB-11) e
 * gli indirizzi del pannello con uno slug tradotti per l'app. La griglia:
 * moderatore, admin e super admin dentro; referente di un locale e utente
 * comune fuori (404); token non sbloccato (423). Nessuna rotta è
 * pericolosa: con la password vecchia di 16 minuti si legge e si segna.
 */
beforeEach(function (): void {
    $this->s = VenueIsolationScenario::make();
    $this->superAdmin = EditorialSession::user(UserRole::SuperAdmin);
    $this->staff = [$this->s->moderator, $this->s->admin, $this->superAdmin];
    freezeLocal($this->s->city, '2027-03-10 12:00');
});

afterEach(fn () => Carbon::setTestNow());

/** Fuori la gente comune (404), chiuso senza sblocco (423). */
function r14Outside(object $test, string $method, string $url): void
{
    foreach ([$test->s->ownerA, $test->s->plainUser] as $outsider) {
        EditorialSession::as($test, EditorialSession::token($outsider))->json($method, $url)->assertNotFound();
    }
    EditorialSession::as($test, EditorialSession::token($test->s->moderator, unlocked: false))->json($method, $url)
        ->assertStatus(423)->assertJsonPath('error.code', 'EDITORIAL_LOCKED');
}

function r14As(object $test, User $user, int $passwordMinutesAgo = 0): object
{
    return EditorialSession::as($test, EditorialSession::token($user, passwordMinutesAgo: $passwordMinutesAgo));
}

/** L'avviso del mattino come lo scrive `sponsorships:pacing-check`. */
function r14Pacing(User $user): void
{
    $user->notifyNow(new SponsorshipPacingAlert(
        [['name' => 'Caffè Pedrocchi · Home', 'state' => 'in ritardo']],
        SponsorshipResource::getUrl('index', panel: 'admin'),
    ));
}

// ------------------------------------------------------------- calendario

it('dà a tutta la redazione le date del mese di tutte le città, con titolo, colore e ora della città', function (): void {
    $lisbon = City::factory()->create(['name' => 'Lisbona', 'slug' => 'lisbona', 'timezone' => 'Europe/Lisbon']);
    $inside = occurrenceAtLocal($this->s->city, $this->s->category, '2027-03-31 23:30', '2027-04-01 01:00',
        event: ['title' => 'Notte al Portello', 'status' => EventStatus::Pending], venue: $this->s->venueA);
    $first = occurrenceAtLocal($this->s->city, $this->s->category, '2027-03-01 00:10', event: ['title' => 'Alba in piazza']);
    $abroad = occurrenceAtLocal($lisbon, $this->s->category, '2027-03-15 21:00', event: ['title' => 'Fado al Chiado']);
    $after = occurrenceAtLocal($this->s->city, $this->s->category, '2027-04-01 00:30', event: ['title' => 'Dopo la fine']);
    $before = occurrenceAtLocal($this->s->city, $this->s->category, '2027-02-28 23:50', event: ['title' => 'Prima dell\'inizio']);
    $trashed = occurrenceAtLocal($this->s->city, $this->s->category, '2027-03-20 21:00', event: ['title' => 'Nel cestino']);
    $trashed->event->delete();
    $url = '/api/v1/editorial/calendar?from=2027-03-01&to=2027-03-31';

    r14Outside($this, 'GET', $url);
    foreach ($this->staff as $person) {
        $response = r14As($this, $person, passwordMinutesAgo: 16)->getJson($url)->assertOk()
            ->assertJsonPath('meta.from', '2027-03-01')
            ->assertJsonPath('meta.to', '2027-03-31')
            ->assertJsonPath('meta.timezone', $this->s->city->timezone)
            ->assertJsonPath('meta.truncated', false)
            ->assertJsonPath('meta.legend.0.value', EventStatus::Draft->value);
        $ids = collect($response->json('data'))->pluck('id')->all();
        expect($ids)->toBe([$first->id, $abroad->id, $inside->id]);
    }

    $rows = collect(r14As($this, $this->s->moderator)->getJson($url)->json('data'))->keyBy('id');
    // Il titolo e il colore del pannello: «Evento · Locale», arancione per «in attesa».
    expect($rows[$inside->id]['title'])->toBe('Notte al Portello · '.$this->s->venueA->name)
        ->and($rows[$inside->id]['color'])->toBe(EditorialCalendar::color(EventStatus::Pending))
        ->and($rows[$inside->id]['event_status']['value'])->toBe('pending')
        ->and($rows[$inside->id]['event_id'])->toBe($inside->event_id)
        ->and($rows[$inside->id]['date'])->toBe('2027-03-31')
        ->and($rows[$inside->id]['starts_at'])->toBe('2027-03-31T23:30:00+02:00')
        ->and($rows[$inside->id]['ends_at'])->toBe('2027-04-01T01:00:00+02:00')
        // Il moderatore apre la scheda ma non modifica, come nel pannello.
        ->and($rows[$inside->id]['abilities'])->toBe(['open' => true, 'edit' => false])
        // L'ora di Lisbona, nel fuso di Lisbona.
        ->and($rows[$abroad->id]['starts_at'])->toBe('2027-03-15T21:00:00+00:00')
        ->and($rows[$abroad->id]['city']['name'])->toBe('Lisbona');
    expect(collect(r14As($this, $this->s->admin)->getJson($url)->json('data'))->firstWhere('id', $inside->id)['abilities'])
        ->toBe(['open' => true, 'edit' => true]);
    unset($after, $before);
});

it('usa la stessa regola del riquadro del pannello', function (): void {
    $one = occurrenceAtLocal($this->s->city, $this->s->category, '2027-03-12 21:00', event: ['title' => 'Concerto in corte']);
    $from = Carbon::parse('2027-03-01 00:00', $this->s->city->timezone)->utc();
    $until = Carbon::parse('2027-03-31 23:59', $this->s->city->timezone)->utc();

    $panel = app(EditorialCalendar::class)->query($from->toImmutable(), $until->toImmutable())->pluck('id')->all();
    $app = collect(r14As($this, $this->s->admin)->getJson('/api/v1/editorial/calendar?from=2027-03-01&to=2027-03-31')->json('data'))->pluck('id')->all();

    expect($app)->toBe($panel)->and($app)->toContain($one->id);
});

it('rifiuta un intervallo rovesciato, troppo lungo o scritto male', function (): void {
    $as = r14As($this, $this->s->admin);
    $as->getJson('/api/v1/editorial/calendar?from=2027-03-10&to=2027-03-01')->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['to']]]);
    $as->getJson('/api/v1/editorial/calendar?from=2027-01-01&to=2027-03-31')->assertUnprocessable()
        ->assertJsonPath('error.fields.to.0', __('editorial-calendar.too_long', ['days' => EditorialCalendar::MAX_DAYS]));
    $as->getJson('/api/v1/editorial/calendar?from=marzo&to=2027-03-31')->assertUnprocessable();
    $as->getJson('/api/v1/editorial/calendar')->assertUnprocessable();
    // Sei settimane intere, come la griglia di un mese, passano.
    $as->getJson('/api/v1/editorial/calendar?from=2027-02-22&to=2027-04-04')->assertOk();
});

// ------------------------------------------------------------- campanella

it('mostra nella campanella l\'avviso del mattino solo a chi lo riceve, cioè chi gestisce le sponsorizzazioni', function (): void {
    // Il comando avvisa `sponsorships.manage`: admin e super admin, non il moderatore.
    expect($this->s->admin->can(Permission::ManageSponsorships->value))->toBeTrue()
        ->and($this->superAdmin->can(Permission::ManageSponsorships->value))->toBeTrue()
        ->and($this->s->moderator->can(Permission::ManageSponsorships->value))->toBeFalse();
    r14Pacing($this->s->admin);
    r14Pacing($this->superAdmin);
    // Un avviso che non ha la forma della campanella resta nella casella dell'account.
    $this->s->admin->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'saved_weekly', 'data' => ['title' => 'I tuoi salvati'], 'read_at' => null]);
    $url = '/api/v1/editorial/notifications';

    r14Outside($this, 'GET', $url);
    r14As($this, $this->s->moderator)->getJson($url)->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('meta.unread', 0);
    $row = r14As($this, $this->s->admin, passwordMinutesAgo: 16)->getJson($url)->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.unread', 1)
        ->assertJsonPath('meta.per_page', 50)
        ->json('data.0');
    expect($row['title'])->toBe(trans_choice('sponsorship-panel.pacing_check.title', 1, ['count' => 1]))
        ->and($row['body'])->toContain('Caffè Pedrocchi')
        ->and($row['status'])->toBe('warning')
        ->and($row['category'])->toBe('sponsorships')
        ->and($row['url'])->toBe(SponsorshipResource::getUrl('index', panel: 'admin'))
        ->and($row['actions'][0]['label'])->toBe(__('sponsorship-panel.pacing_check.action'))
        ->and($row['read'])->toBeFalse();
    r14As($this, $this->superAdmin)->getJson($url)->assertOk()->assertJsonPath('meta.total', 1);
});

it('segna, toglie e svuota come la campanella del pannello, solo sulle proprie notifiche', function (): void {
    r14Pacing($this->s->admin);
    r14Pacing($this->superAdmin);
    $mine = (string) $this->s->admin->notifications()->value('id');
    $theirs = (string) $this->superAdmin->notifications()->value('id');
    $other = $this->s->admin->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'saved_weekly', 'data' => ['title' => 'I tuoi salvati'], 'read_at' => null]);
    $base = '/api/v1/editorial/notifications/';

    r14Outside($this, 'POST', $base.$mine.'/read');
    r14Outside($this, 'POST', $base.'read-all');
    r14Outside($this, 'POST', $base.'clear');

    $as = r14As($this, $this->s->admin, passwordMinutesAgo: 16);
    $as->postJson($base.$mine.'/read')->assertOk()->assertJsonPath('data.read', true)->assertJsonPath('meta.unread', 0);
    $as->postJson($base.$mine.'/unread')->assertOk()->assertJsonPath('data.read', false)->assertJsonPath('meta.unread', 1);
    // Quella di un altro, o un avviso che non è della campanella: non si trova.
    $as->postJson($base.$theirs.'/read')->assertNotFound();
    $as->postJson($base.$other->id.'/read')->assertNotFound();
    $as->postJson($base.$theirs.'/remove')->assertNotFound();
    $as->postJson($base.'non-un-id/read')->assertNotFound();
    expect($this->superAdmin->notifications()->whereNull('read_at')->count())->toBe(1);

    $as->postJson($base.'read-all')->assertOk()->assertJsonPath('meta.unread', 0);
    expect($other->fresh()->read_at)->toBeNull();
    $as->postJson($base.$mine.'/remove')->assertOk()->assertJsonPath('meta.message', __('editorial-calendar.removed'));
    $as->getJson('/api/v1/editorial/notifications')->assertJsonPath('meta.total', 0);

    r14Pacing($this->s->admin);
    $as->postJson($base.'clear')->assertOk()->assertJsonPath('data.removed', 1);
    // Svuotare la campanella non tocca gli altri avvisi dell'account né quelli degli altri.
    expect($other->fresh())->not->toBeNull()
        ->and($this->superAdmin->notifications()->count())->toBe(1);
});

// -------------------------------------------------- indirizzi del pannello

it('traduce lo slug di un indirizzo del pannello nell\'id della scheda, anche nel cestino, per chi la può vedere', function (): void {
    $event = $this->s->draftEventA;
    $venue = $this->s->venueA;
    r14Outside($this, 'GET', '/api/v1/editorial/links/events/'.$event->slug);

    foreach ($this->staff as $person) {
        r14As($this, $person)->getJson('/api/v1/editorial/links/events/'.$event->slug)->assertOk()
            ->assertJsonPath('data', ['type' => 'events', 'id' => $event->id, 'title' => $event->title]);
        r14As($this, $person)->getJson('/api/v1/editorial/links/venues/'.$venue->slug)->assertOk()
            ->assertJsonPath('data.id', $venue->id);
    }
    $event->delete();
    r14As($this, $this->s->admin)->getJson('/api/v1/editorial/links/events/'.$event->slug)->assertOk()->assertJsonPath('data.id', $event->id);
    r14As($this, $this->s->admin)->getJson('/api/v1/editorial/links/events/non-esiste')->assertNotFound();
    r14As($this, $this->s->admin)->getJson('/api/v1/editorial/links/users/'.$this->s->admin->id)->assertNotFound();
});
