<?php

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\Events\Pages\EditEvent;
use App\Filament\Admin\Resources\Events\RelationManagers\OccurrencesRelationManager;
use App\Models\Booking;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventRecurrence;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\EditorialSession;
use Tests\Support\VenueIsolationScenario;

/*
 * R3 — le date di un evento nella redazione dell'app (la sezione «Date»
 * della pagina «Modifica evento»): elenco, nuova, modifica con listino e
 * lineup, sposta, annulla, tutto esaurito, elimina, biglietteria. La griglia
 * dei ruoli e il confronto con il pannello sugli stessi dati.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->s = VenueIsolationScenario::make();
    $this->superAdmin = EditorialSession::user(UserRole::SuperAdmin);
    $this->admins = [$this->s->admin, $this->superAdmin];
    $this->outsiders = [$this->s->ownerA, $this->s->plainUser];
    $this->event = $this->s->draftEventA;
    $this->url = '/api/v1/editorial/events/'.$this->event->id.'/dates';
});

/** Una serie di quattro giovedì da una ricorrenza, alle 21 di Padova. */
function weeklySeries(Event $event): array
{
    $recurrence = EventRecurrence::query()->create(['event_id' => $event->id, 'rrule' => 'FREQ=WEEKLY;COUNT=4', 'until' => null]);
    $start = CarbonImmutable::parse('2026-12-03 21:00', 'Europe/Rome');

    return collect(range(0, 3))->map(fn (int $week): EventOccurrence => EventOccurrence::factory()->create([
        'event_id' => $event->id,
        'recurrence_id' => $recurrence->id,
        'starts_at' => $start->addWeeks($week)->utc(),
        'ends_at' => null,
        'doors_at' => null,
        'is_exception' => false,
    ]))->all();
}

it('elenca le date a chi vede l\'evento, con i gesti solo per chi lo modifica', function (): void {
    $dates = weeklySeries($this->event);

    $this->getJson($this->url)->assertUnauthorized();
    foreach ($this->outsiders as $outsider) {
        EditorialSession::as($this, EditorialSession::token($outsider))->getJson($this->url)->assertNotFound();
    }
    EditorialSession::as($this, EditorialSession::token($this->s->moderator, unlocked: false))->getJson($this->url)
        ->assertStatus(423)->assertJsonPath('error.code', 'EDITORIAL_LOCKED');

    // Il moderatore le vede in sola lettura, come nella pagina dell'evento del pannello.
    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->getJson($this->url)->assertOk()
        ->assertJsonPath('meta.total', 4)
        ->assertJsonPath('meta.abilities.create', false)
        ->assertJsonPath('data.0.abilities.update', false)
        ->assertJsonPath('data.0.abilities.destroy', false);

    foreach ($this->admins as $admin) {
        EditorialSession::as($this, EditorialSession::token($admin))->getJson($this->url)->assertOk()
            ->assertJsonPath('meta.event.timezone', 'Europe/Rome')
            ->assertJsonPath('meta.abilities.create', true)
            ->assertJsonPath('data.0.id', $dates[0]->id)
            ->assertJsonPath('data.0.series', true)
            ->assertJsonPath('data.0.starts_at', '2026-12-03T20:00:00+00:00')
            ->assertJsonPath('data.0.abilities.update', true)
            ->assertJsonPath('data.0.abilities.cancel', true)
            ->assertJsonPath('data.0.abilities.destroy', true);
    }
});

it('aggiunge e modifica una data con locale, stato, posti, listino della sera e lineup', function (): void {
    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->postJson($this->url, ['starts_at' => '2026-12-10T21:00:00'])->assertForbidden();

    $token = EditorialSession::token($this->s->admin);
    $created = EditorialSession::as($this, $token)->postJson($this->url, [
        'venue_id' => $this->s->venueB->id,
        'starts_at' => '2026-12-10T21:00:00',
        'ends_at' => '2026-12-10T23:00:00',
        'doors_at' => '2026-12-10T20:30:00',
        'status' => 'scheduled',
        'capacity' => 180,
        'capacity_left' => 40,
        'highlight' => 'Ultimi posti',
        'status_note' => '<p>Ingresso dal cortile</p>',
        'practical_details' => ['accessibility' => 'yes', 'parking_notes' => 'Parcheggio in piazza'],
        'cost_breakdown' => ['admission' => 12.5],
        'ticket_tiers' => [['name' => 'Platea', 'price' => 15, 'status' => 'available'], ['name' => 'Galleria', 'price' => 10, 'status' => 'sold_out']],
        'lineup' => [['name' => 'Trio Aurora', 'role' => 'live', 'starts_at' => '2026-12-10T21:30:00', 'url' => 'https://example.com/trio']],
    ])->assertCreated()
        ->assertJsonPath('data.venue.id', $this->s->venueB->id)
        ->assertJsonPath('data.starts_at', '2026-12-10T20:00:00+00:00')
        ->assertJsonPath('data.doors_at', '2026-12-10T19:30:00+00:00')
        ->assertJsonPath('data.capacity', 180)
        ->assertJsonPath('data.ticket_tiers.1.name', 'Galleria')
        ->assertJsonPath('data.lineup.0.starts_at', '2026-12-10T20:30:00+00:00')
        ->assertJsonPath('data.practical_details.parking_notes', 'Parcheggio in piazza')
        ->json('data');

    $date = EventOccurrence::query()->findOrFail($created['id']);
    expect($date->ticketTiers()->pluck('event_id')->unique()->all())->toBe([$this->event->id]);

    // Modifica: le chiavi assenti restano, il listino e la lineup si sostituiscono.
    EditorialSession::as($this, $token)->patchJson($this->url.'/'.$date->id, [
        'status' => 'postponed',
        'ticket_tiers' => [['id' => $created['ticket_tiers'][0]['id'], 'name' => 'Platea', 'price' => 18, 'status' => 'available']],
        'lineup' => [],
    ])->assertOk()
        ->assertJsonPath('data.status.value', 'postponed')
        ->assertJsonPath('data.capacity', 180)
        ->assertJsonCount(1, 'data.ticket_tiers')
        ->assertJsonPath('data.ticket_tiers.0.price', 18)
        ->assertJsonCount(0, 'data.lineup');

    // Le stesse regole del modulo del pannello: fine prima dell'inizio, evidenza troppo lunga, ruolo inventato.
    EditorialSession::as($this, $token)->patchJson($this->url.'/'.$date->id, [
        'ends_at' => '2026-12-10T19:00:00',
        'highlight' => str_repeat('x', 41),
        'lineup' => [['name' => 'X', 'role' => 'chitarrista']],
    ])->assertUnprocessable()->assertJsonValidationErrors(['highlight', 'lineup.0.role'], 'error.fields');

    // Le date non hanno un registro attività (`EventOccurrence` non usa `LogsActivity`), né nel pannello né qui.
    expect(Activity::query()->where('subject_type', $date->getMorphClass())->count())->toBe(0);
});

it('sposta e annulla la sola data o la serie da lì in avanti, come il pannello', function (): void {
    $dates = weeklySeries($this->event);
    $token = EditorialSession::token($this->s->admin);

    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->postJson($this->url.'/'.$dates[1]->id.'/shift', ['shift_minutes' => 30])->assertForbidden();

    EditorialSession::as($this, $token)->postJson($this->url.'/'.$dates[1]->id.'/shift', ['shift_minutes' => 30, 'scope' => 'series'])->assertOk()
        ->assertJsonPath('meta.changed', 3);
    expect($dates[0]->fresh()->starts_at->copy()->utc()->format('H:i'))->toBe('20:00')
        ->and($dates[3]->fresh()->starts_at->copy()->utc()->format('H:i'))->toBe('20:30');

    EditorialSession::as($this, $token)->postJson($this->url.'/'.$dates[2]->id.'/status', ['action' => 'cancel', 'status_note' => 'Festa patronale', 'scope' => 'single'])->assertOk()
        ->assertJsonPath('meta.changed', 1)
        ->assertJsonPath('data.status.value', 'cancelled')
        ->assertJsonPath('data.abilities.cancel', false);
    EditorialSession::as($this, $token)->postJson($this->url.'/'.$dates[2]->id.'/status', ['action' => 'cancel'])->assertUnprocessable();

    // «Tutto esaurito» e ritorno: sempre la sola data.
    EditorialSession::as($this, $token)->postJson($this->url.'/'.$dates[0]->id.'/status', ['action' => 'sold_out', 'scope' => 'series'])->assertOk()
        ->assertJsonPath('meta.changed', 1)->assertJsonPath('data.status.value', 'sold_out');
    EditorialSession::as($this, $token)->postJson($this->url.'/'.$dates[0]->id.'/status', ['action' => 'sold_out'])->assertOk()
        ->assertJsonPath('data.status.value', 'scheduled');
    expect($dates[1]->fresh()->status)->toBe(OccurrenceStatus::Scheduled);

    // Il pannello fa lo stesso con lo stesso servizio.
    $this->withoutToken()->actingAs($this->s->admin, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->get('/admin/events/'.$this->event->getRouteKey().'/edit')->assertOk();
    Livewire::test(OccurrencesRelationManager::class, ['ownerRecord' => $this->event, 'pageClass' => EditEvent::class])
        ->callTableAction('sold_out', $dates[3])
        ->assertHasNoTableActionErrors();
    expect($dates[3]->fresh()->status)->toBe(OccurrenceStatus::SoldOut);
});

it('elimina una data solo con la password recente', function (): void {
    $date = weeklySeries($this->event)[0];
    $url = $this->url.'/'.$date->id;

    EditorialSession::as($this, EditorialSession::token($this->s->moderator))->deleteJson($url)->assertForbidden();
    EditorialSession::as($this, EditorialSession::token($this->s->admin, passwordMinutesAgo: 16))->deleteJson($url)
        ->assertStatus(423)->assertJsonPath('error.code', 'PASSWORD_REQUIRED');
    EditorialSession::as($this, EditorialSession::token($this->s->admin))->deleteJson($url)->assertNoContent();

    expect(EventOccurrence::withTrashed()->find($date->id)?->trashed())->toBeTrue();
    EditorialSession::as($this, EditorialSession::token($this->s->admin))->getJson($url)->assertNotFound();
});

it('porta alla biglietteria della data chi la gestisce', function (): void {
    $this->s->venueA->update(['ticketing_enabled' => true]);
    $date = weeklySeries($this->event)[0];

    $row = EditorialSession::as($this, EditorialSession::token($this->s->admin))->getJson($this->url.'/'.$date->id)->assertOk()->json('data');
    expect($row['abilities']['ticketing'])->toBe(Gate::forUser($this->s->admin)->allows('manage', [Booking::class, $date->fresh()]))
        ->and($row['ticketing_path'])->toBe($row['abilities']['ticketing'] ? 'gestione-biglietti/'.$date->id : null);

    // Una data di un altro evento non si apre da qui.
    EditorialSession::as($this, EditorialSession::token($this->s->admin))->getJson($this->url.'/'.$this->s->occurrenceB->id)->assertNotFound();
});
