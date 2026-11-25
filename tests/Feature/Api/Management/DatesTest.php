<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\LineupRole;
use App\Enums\OccurrenceStatus;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Lineup;
use App\Models\Organizer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Support\VenueIsolationScenario;

/*
 * Le date di un evento nell'app di gestione (P2): elenco con i numeri del
 * pannello, aggiungi e modifica nei due moduli (locale e «Date e luoghi»
 * dell'organizzazione), annulla anche la serie, tutto esaurito, elimina,
 * «Ripeti», tutto esaurito sulla prossima data, locandina in PDF. Le regole
 * sono quelle delle sezioni delle date dei pannelli (`EventDates`,
 * `OrganizerEventEditor::afterDateSaved`, `UpdateOccurrencesAction`).
 */
beforeEach(function (): void {
    Notification::fake();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 10:00:00', 'UTC'));
    $this->s = VenueIsolationScenario::make();
    $this->venueUrl = '/api/v1/management/venues/'.$this->s->venueA->id;
    $this->datesUrl = $this->venueUrl.'/events/'.$this->s->publishedEventA->id.'/dates';
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** Un'organizzazione con un evento pubblicato tenuto nel locale B, e il suo proprietario. */
function datesOrganizer(VenueIsolationScenario $s): array
{
    $owner = User::factory()->create();
    $organizer = Organizer::create(['name' => 'Collettivo Portici', 'city_id' => $s->city->id, 'owner_id' => $owner->id, 'is_active' => true]);
    $event = Event::factory()->create([
        'city_id' => $s->city->id, 'category_id' => $s->category->id, 'venue_id' => $s->venueB->id,
        'organizer_id' => $organizer->id, 'created_by' => $owner->id, 'title' => 'Rassegna dei Portici',
        'status' => EventStatus::Published, 'published_at' => CarbonImmutable::now('UTC'),
    ]);
    $date = EventOccurrence::factory()->create(['event_id' => $event->id, 'starts_at' => CarbonImmutable::parse('2026-09-20 18:00:00', 'UTC'), 'ends_at' => null, 'doors_at' => null]);

    return [$organizer, $owner, $event, $date, '/api/v1/management/organizers/'.$organizer->id.'/events/'.$event->id.'/dates'];
}

it('elenca le date dell’evento con posti, artisti, numeri e collegamenti', function (): void {
    Lineup::create(['occurrence_id' => $this->s->occurrenceA->id, 'name' => 'Trio Aurora', 'role' => LineupRole::Live]);
    $this->s->occurrenceA->forceFill(['capacity' => 80, 'capacity_left' => 12, 'highlight' => 'Ultimi posti'])->saveQuietly();
    Sanctum::actingAs($this->s->ownerA);

    $this->getJson($this->datesUrl)->assertOk()
        ->assertJsonPath('data.0.id', $this->s->occurrenceA->id)
        ->assertJsonPath('data.0.starts_at', '2026-09-11T19:00:00+00:00')
        ->assertJsonPath('data.0.capacity_left', 12)
        ->assertJsonPath('data.0.highlight', 'Ultimi posti')
        ->assertJsonPath('data.0.lineup.0.name', 'Trio Aurora')
        ->assertJsonPath('data.0.lineup.0.role.value', 'live')
        ->assertJsonPath('data.0.stats.views', 0)
        ->assertJsonPath('data.0.ticketing_date_id', $this->s->occurrenceA->id)
        ->assertJsonPath('data.0.poster_pdf', true)
        ->assertJsonPath('data.0.abilities.delete', true)
        ->assertJsonPath('data.0.abilities.cancel', true)
        ->assertJsonPath('meta.event.timezone', 'Europe/Rome')
        ->assertJsonPath('meta.abilities.create', true)
        ->assertJsonPath('meta.abilities.recurrence', true)
        ->assertJsonPath('meta.recurrence', null);

    // Il collaboratore vede e modifica le date, ma la biglietteria resta del referente (decisione 6).
    Sanctum::actingAs($this->s->editorA);
    $this->getJson($this->datesUrl)->assertOk()
        ->assertJsonPath('data.0.ticketing_date_id', null)
        ->assertJsonPath('data.0.abilities.update', true);

    $this->getJson($this->datesUrl.'/'.$this->s->occurrenceA->id)->assertOk()
        ->assertJsonPath('data.id', $this->s->occurrenceA->id)
        ->assertJsonPath('data.lineup.0.name', 'Trio Aurora')
        ->assertJsonPath('meta.event.id', $this->s->publishedEventA->id);
    $this->getJson($this->datesUrl.'?period=past')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($this->datesUrl.'?period=altro')->assertUnprocessable();
});

it('chiude le date a chi non è del soggetto, al locale sospeso e al solo personale d’ingresso', function (): void {
    $staff = User::factory()->create();
    $this->s->occurrenceA->checkinStaff()->attach($staff);
    $patch = $this->datesUrl.'/'.$this->s->occurrenceA->id;

    foreach ([$this->s->plainUser, $this->s->ownerB, $staff] as $user) {
        Sanctum::actingAs($user);
        $this->getJson($this->datesUrl)->assertNotFound();
        $this->patchJson($patch, ['highlight' => 'Preso'])->assertNotFound();
    }

    // La data di un altro evento non si raggiunge passando per questo.
    Sanctum::actingAs($this->s->ownerA);
    $this->patchJson($this->datesUrl.'/'.$this->s->occurrenceB->id, ['highlight' => 'Preso'])->assertNotFound();
    $this->getJson($this->venueUrl.'/events/'.$this->s->publishedEventB->id.'/dates')->assertNotFound();

    $this->s->venueA->forceFill(['status' => 'suspended'])->saveQuietly();
    $this->getJson($this->datesUrl)->assertNotFound();
    $this->postJson($this->datesUrl, ['starts_at' => '2026-10-01T21:00:00+02:00'])->assertNotFound();

    expect($this->s->occurrenceA->fresh()->highlight)->toBeNull();
});

it('aggiunge una data del locale con posti, evidenza, lineup, informazioni pratiche e costi', function (): void {
    Sanctum::actingAs($this->s->ownerA);

    $response = $this->postJson($this->datesUrl, [
        'starts_at' => '2026-10-02T21:30:00+02:00',
        'ends_at' => '2026-10-02T23:30:00+02:00',
        'capacity' => 120, 'capacity_left' => 120, 'highlight' => 'Ospite speciale',
        'lineup' => [['name' => 'Banda Belzoni', 'role' => 'live'], ['name' => 'DJ Portello', 'role' => 'dj']],
        'practical_details' => ['accessibility' => 'yes', 'entrance_notes' => 'Ingresso dal cortile'],
        'cost_breakdown' => ['admission' => '8.50', 'drink' => null],
        // I campi del modulo dell'organizzazione non valgono per il locale.
        'status' => 'postponed', 'status_note' => 'Non deve entrare',
    ])->assertCreated()
        ->assertJsonPath('data.starts_at', '2026-10-02T19:30:00+00:00')
        ->assertJsonPath('data.status.value', 'scheduled')
        ->assertJsonPath('data.lineup.1.name', 'DJ Portello')
        ->assertJsonPath('data.practical_details.entrance_notes', 'Ingresso dal cortile')
        ->assertJsonPath('meta.sent_to_review', false);

    $date = EventOccurrence::query()->findOrFail($response->json('data.id'));
    expect($date->capacity)->toBe(120)
        ->and($date->status_note)->toBeNull()
        ->and($date->lineups()->count())->toBe(2)
        ->and($date->cost_breakdown['admission'])->toEqual('8.50')
        // Il locale che aggiunge una data non rimanda l'evento in revisione, come nel pannello.
        ->and($this->s->publishedEventA->fresh()->status)->toBe(EventStatus::Published);

    $this->postJson($this->datesUrl, [])->assertUnprocessable()->assertJsonValidationErrors(['starts_at'], 'error.fields');
    $this->postJson($this->datesUrl, ['starts_at' => '2026-10-02T21:30:00+02:00', 'ends_at' => '2026-10-02T20:00:00+02:00'])
        ->assertUnprocessable()->assertJsonValidationErrors(['ends_at'], 'error.fields');
    $this->postJson($this->datesUrl, ['starts_at' => '2026-10-02T21:30:00+02:00', 'highlight' => str_repeat('x', 41), 'lineup' => [['name' => '', 'role' => 'banda']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['highlight', 'lineup.0.name', 'lineup.0.role'], 'error.fields');
    $this->postJson($this->datesUrl, ['starts_at' => '2026-10-02T21:30:00+02:00', 'cost_breakdown' => ['admission' => -1, 'tip' => 2]])
        ->assertUnprocessable()->assertJsonValidationErrors(['cost_breakdown'], 'error.fields');
});

it('lascia al collaboratore informazioni pratiche e costi della data, che l’endpoint della biglietteria gli nega', function (): void {
    Sanctum::actingAs($this->s->editorA);
    $body = ['practical_details' => ['entrance_notes' => 'Citofono 2', 'membership' => 'required'], 'cost_breakdown' => ['admission' => 5]];

    // L'endpoint di prima resta com'è per le app installate: lì decide la biglietteria.
    $this->patchJson('/api/v1/management/dates/'.$this->s->occurrenceA->id.'/details', [...$body, 'cost_breakdown' => ['admission' => 5]])->assertForbidden();

    $this->patchJson($this->datesUrl.'/'.$this->s->occurrenceA->id, $body)->assertOk()
        ->assertJsonPath('data.practical_details.entrance_notes', 'Citofono 2')
        ->assertJsonPath('data.cost_breakdown.admission', 5);
});

it('in modifica non tocca le chiavi assenti, sostituisce le informazioni pratiche per intero e allinea la lineup', function (): void {
    $date = $this->s->occurrenceA;
    $date->forceFill(['capacity' => 50, 'practical_details' => ['entrance_notes' => 'Vecchia', 'parking_notes' => 'Piazza'], 'cost_breakdown' => ['admission' => 10]])->saveQuietly();
    $keep = Lineup::create(['occurrence_id' => $date->id, 'name' => 'Trio', 'role' => LineupRole::Live]);
    $drop = Lineup::create(['occurrence_id' => $date->id, 'name' => 'Ospite', 'role' => LineupRole::SpecialGuest]);
    $foreign = Lineup::create(['occurrence_id' => $this->s->occurrenceB->id, 'name' => 'Altrui', 'role' => LineupRole::Live]);
    Sanctum::actingAs($this->s->ownerA);

    $this->patchJson($this->datesUrl.'/'.$date->id, [
        'practical_details' => ['entrance_notes' => 'Nuova'],
        'lineup' => [['id' => $keep->id, 'name' => 'Trio Aurora', 'role' => 'opening'], ['id' => $foreign->id, 'name' => 'Nuovo nome', 'role' => 'dj']],
    ])->assertOk();

    $date->refresh();
    expect($date->capacity)->toBe(50)
        ->and($date->starts_at->toIso8601String())->toBe('2026-09-11T19:00:00+00:00')
        ->and($date->practical_details)->toBe(['entrance_notes' => 'Nuova'])
        ->and($date->cost_breakdown)->toEqual(['admission' => 10])
        ->and($keep->fresh()->name)->toBe('Trio Aurora')
        ->and($keep->fresh()->role)->toBe(LineupRole::Opening)
        ->and(Lineup::query()->whereKey($drop->id)->exists())->toBeFalse()
        // Un id che non è di questa data non si tocca: diventa un artista nuovo.
        ->and($foreign->fresh()->name)->toBe('Altrui')
        ->and($date->lineups()->pluck('name')->sort()->values()->all())->toBe(['Nuovo nome', 'Trio Aurora']);

    // La fine che arriva da sola si confronta con l'inizio salvato.
    $this->patchJson($this->datesUrl.'/'.$date->id, ['ends_at' => '2026-09-11T18:00:00+00:00'])
        ->assertUnprocessable()->assertJsonValidationErrors(['ends_at'], 'error.fields');
    // `null` svuota informazioni pratiche e costi: si torna a quelli dell'evento o del locale.
    $this->patchJson($this->datesUrl.'/'.$date->id, ['practical_details' => null, 'cost_breakdown' => null])->assertOk()
        ->assertJsonPath('data.practical_details', [])
        ->assertJsonPath('data.cost_breakdown', []);
});

it('annulla una data o tutta la serie, segna tutto esaurito e la rimette in vendita', function (): void {
    Sanctum::actingAs($this->s->ownerA);
    $recurrenceUrl = $this->venueUrl.'/events/'.$this->s->publishedEventA->id.'/recurrence';

    $created = $this->putJson($recurrenceUrl, ['frequency' => 'weekly', 'weekdays' => ['fri'], 'until' => '2026-10-09'])->assertOk()
        ->assertJsonPath('data.frequency', 'weekly')
        ->assertJsonPath('data.weekdays', ['fri'])
        ->assertJsonPath('data.until', '2026-10-09')
        ->json('meta.created');
    expect($created)->toBeGreaterThanOrEqual(3);
    $this->getJson($this->datesUrl)->assertOk()->assertJsonPath('meta.recurrence.frequency', 'weekly');

    $series = EventOccurrence::query()->where('event_id', $this->s->publishedEventA->id)->whereNotNull('recurrence_id')->orderBy('starts_at')->get();
    [$first, $second] = [$series[0], $series[1]];
    $exception = $series->last();
    $exception->forceFill(['is_exception' => true])->saveQuietly();

    $this->postJson($this->datesUrl.'/'.$second->id.'/status', ['status' => 'cancelled', 'scope' => 'series'])->assertOk()
        ->assertJsonPath('data.status.value', 'cancelled')
        ->assertJsonPath('data.abilities.cancel', false)
        ->assertJsonPath('meta.changed', $series->count() - 2);
    expect($first->fresh()->status)->toBe(OccurrenceStatus::Scheduled)
        ->and($exception->fresh()->status)->toBe(OccurrenceStatus::Scheduled);
    $this->postJson($this->datesUrl.'/'.$second->id.'/status', ['status' => 'cancelled'])->assertUnprocessable();

    // L'esaurito vale sempre per la sola data, anche se arriva «serie».
    $this->postJson($this->datesUrl.'/'.$first->id.'/status', ['status' => 'sold_out', 'scope' => 'series'])->assertOk()->assertJsonPath('meta.changed', 1);
    $this->postJson($this->datesUrl.'/'.$first->id.'/status', ['status' => 'scheduled'])->assertOk()->assertJsonPath('data.status.value', 'scheduled');
    // Si rimette in vendita solo una data esaurita, non una annullata.
    $this->postJson($this->datesUrl.'/'.$second->id.'/status', ['status' => 'scheduled'])->assertUnprocessable();
    $this->postJson($this->datesUrl.'/'.$first->id.'/status', ['status' => 'postponed'])->assertUnprocessable();
});

it('elimina una data del locale e ripete l’evento solo se ha già una data', function (): void {
    Sanctum::actingAs($this->s->editorA);
    $draftDates = $this->venueUrl.'/events/'.$this->s->draftEventA->id;
    $this->putJson($draftDates.'/recurrence', ['frequency' => 'weekly'])->assertUnprocessable()->assertJsonValidationErrors(['frequency'], 'error.fields');
    $this->putJson($draftDates.'/recurrence', ['frequency' => 'ogni tanto'])->assertUnprocessable();

    Sanctum::actingAs($this->s->ownerA);
    $this->deleteJson($this->datesUrl.'/'.$this->s->occurrenceA->id)->assertNoContent();
    expect(EventOccurrence::query()->whereKey($this->s->occurrenceA->id)->exists())->toBeFalse();
});

it('segna tutto esaurito la prossima data dalla card dell’elenco, con un valore esplicito', function (): void {
    Sanctum::actingAs($this->s->editorA);
    $url = $this->venueUrl.'/events/'.$this->s->publishedEventA->id.'/next-date/sold-out';

    $this->getJson($this->venueUrl.'/events?tab=upcoming')->assertOk()
        ->assertJsonPath('data.0.next_date.id', $this->s->occurrenceA->id)
        ->assertJsonPath('data.0.next_date.status.value', 'scheduled');

    $this->putJson($url, ['sold_out' => true])->assertOk()->assertJsonPath('data.status.value', 'sold_out')->assertJsonPath('meta.changed', 1);
    $this->putJson($url, ['sold_out' => true])->assertOk()->assertJsonPath('meta.changed', 0);
    $this->getJson($this->venueUrl.'/events/'.$this->s->publishedEventA->id)->assertOk()->assertJsonPath('data.next_date.status.value', 'sold_out');
    $this->putJson($url, ['sold_out' => false])->assertOk()->assertJsonPath('data.status.value', 'scheduled');

    $this->putJson($this->venueUrl.'/events/'.$this->s->draftEventA->id.'/next-date/sold-out', ['sold_out' => true])->assertUnprocessable();
});

it('dà all’organizzazione «Date e luoghi» con lo stato completo e il ritorno in revisione del pannello', function (): void {
    [$organizer, $owner, $event, $date, $url] = datesOrganizer($this->s);
    $member = User::factory()->create();
    $organizer->users()->attach($member);
    Sanctum::actingAs($member);

    $this->getJson($url)->assertOk()
        ->assertJsonPath('data.0.id', $date->id)
        ->assertJsonPath('data.0.abilities.update', true)
        ->assertJsonPath('data.0.abilities.delete', false)
        ->assertJsonPath('data.0.abilities.cancel', false)
        ->assertJsonPath('meta.abilities.recurrence', false);

    // Orario, stato e avviso al pubblico restano immediati.
    $this->patchJson($url.'/'.$date->id, [
        'starts_at' => '2026-09-20T21:00:00+02:00', 'status' => 'postponed', 'status_note' => '<p>Rinviata per pioggia</p>',
        'capacity' => 10, 'lineup' => [['name' => 'Non entra', 'role' => 'live']],
    ])->assertOk()
        ->assertJsonPath('data.status.value', 'postponed')
        ->assertJsonPath('data.status_note', '<p>Rinviata per pioggia</p>')
        ->assertJsonPath('data.capacity', null)
        ->assertJsonPath('meta.sent_to_review', false);
    expect($event->fresh()->status)->toBe(EventStatus::Published)
        ->and($date->lineups()->count())->toBe(0);

    // Portata in un altro locale: torna dalla redazione.
    $this->patchJson($url.'/'.$date->id, ['venue_id' => $this->s->venueA->id])->assertOk()
        ->assertJsonPath('data.venue.id', $this->s->venueA->id)
        ->assertJsonPath('meta.sent_to_review', true);
    expect($event->fresh()->status)->toBe(EventStatus::Pending);

    // Una data nuova su un evento pubblicato, lo stesso.
    $event->refresh()->forceFill(['status' => EventStatus::Published])->saveQuietly();
    Sanctum::actingAs($owner);
    $this->postJson($url, ['starts_at' => '2026-09-27', 'is_all_day' => true])->assertCreated()
        ->assertJsonPath('data.is_all_day', true)
        ->assertJsonPath('data.status.value', 'scheduled')
        ->assertJsonPath('meta.sent_to_review', true);

    $this->patchJson($url.'/'.$date->id, ['status' => 'perduta', 'status_note' => str_repeat('x', 2001)])
        ->assertUnprocessable()->assertJsonValidationErrors(['status', 'status_note'], 'error.fields');
    $this->s->venueB->forceFill(['status' => 'pending'])->saveQuietly();
    $this->patchJson($url.'/'.$date->id, ['venue_id' => $this->s->venueB->id])->assertUnprocessable()->assertJsonValidationErrors(['venue_id'], 'error.fields');

    // Le rotte del solo locale non esistono sotto l'organizzazione.
    $this->deleteJson($url.'/'.$date->id)->assertStatus(405);
    $this->postJson($url.'/'.$date->id.'/status', ['status' => 'cancelled'])->assertNotFound();
    $this->putJson('/api/v1/management/organizers/'.$organizer->id.'/events/'.$event->id.'/recurrence', ['frequency' => 'weekly'])->assertNotFound();

    // Il locale che ospita l'evento dell'organizzazione non lo gestisce da qui.
    Sanctum::actingAs($this->s->ownerB);
    $this->getJson($url)->assertNotFound();
});

it('scarica la locandina in PDF delle date degli eventi pubblicati', function (): void {
    Sanctum::actingAs($this->s->editorA);

    $response = $this->get($this->datesUrl.'/'.$this->s->occurrenceA->id.'/poster.pdf')->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(substr((string) $response->getContent(), 0, 4))->toBe('%PDF');

    $draft = EventOccurrence::factory()->create(['event_id' => $this->s->draftEventA->id, 'starts_at' => CarbonImmutable::parse('2026-10-01 19:00:00', 'UTC'), 'ends_at' => null, 'doors_at' => null]);
    $this->get($this->venueUrl.'/events/'.$this->s->draftEventA->id.'/dates/'.$draft->id.'/poster.pdf')->assertNotFound();
});
