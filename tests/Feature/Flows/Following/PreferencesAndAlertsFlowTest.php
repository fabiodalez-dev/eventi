<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Models\AvailabilityAlert;
use App\Models\BookingOpeningAlert;
use App\Models\EditionAlert;
use App\Models\Organizer;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/*
 * Flussi di personalizzazione: gli interessi di contenuto che decidono che
 * cosa si vede, e gli avvisi «di una volta» — tornano i posti, aprono le
 * prenotazioni, torna l'evento — chiesti dal sito o dall'app e chiusi dal
 * primo avviso spedito.
 */

beforeEach(function (): void {
    Notification::fake();
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-11-12 12:00');
    $this->user = User::factory()->create();
});

afterEach(fn () => Carbon::setTestNow());

it('sceglie dal profilo le categorie preferite e quelle da nascondere, e il feed segue le scelte sul sito e nell app', function (): void {
    $teatro = testCategory(['name' => 'Teatro sperimentale']);
    $circolo = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    occurrenceAtLocal($this->city, $this->category, '2026-11-14 21:00', event: ['title' => 'Musica preferita']);
    occurrenceAtLocal($this->city, $teatro, '2026-11-15 21:00', venue: $circolo, event: ['title' => 'Teatro da nascondere']);

    $this->actingAs($this->user);
    // Segue il circolo, che ospita anche il teatro.
    $this->post('/segui', ['type' => 'venue', 'id' => $circolo->id])->assertRedirect();
    $this->get('/il-mio-feed')->assertOk()->assertSee('Teatro da nascondere')->assertDontSee('Musica preferita');

    $this->get('/profilo/interessi')->assertOk()->assertSee('I miei interessi')->assertSee('Teatro sperimentale');
    $this->patch('/profilo/interessi', [
        'mode' => 'all',
        'choices' => [$this->category->id => 'interested', $teatro->id => 'hidden'],
        'inferred_ads' => '1',
    ])->assertRedirect(route('account.content-preferences'))->assertSessionHas('status');

    $preferenze = $this->user->fresh()->content_preferences;
    expect($preferenze['categories'])->toBe([$this->category->id])
        ->and($preferenze['hidden_categories'])->toBe([$teatro->id]);

    // La categoria preferita entra nel feed anche senza un follow; quella nascosta ne esce, anche dal locale seguito.
    $this->get('/il-mio-feed')->assertOk()->assertSee('Musica preferita')->assertDontSee('Teatro da nascondere');
    expect($this->getJson('/api/v1/me/feed')->assertOk()->json('data.*.title'))->toBe(['Musica preferita']);
    $this->getJson('/api/v1/me/content-preferences')->assertOk()
        ->assertJsonPath('data.selection.categories', [$this->category->id])
        ->assertJsonPath('data.selection.hidden_categories', [$teatro->id]);

    // Dall'app cambia idea: niente più nascosti né preferiti.
    $this->patchJson('/api/v1/me/content-preferences', ['mode' => 'all', 'categories' => [], 'hidden_categories' => [], 'inferred_ads' => true])->assertOk()
        ->assertJsonPath('data.selection.hidden_categories', []);
    expect($this->getJson('/api/v1/me/feed')->json('data.*.title'))->toBe(['Teatro da nascondere']);
    // Le preferenze di contenuto non creano follow di nascosto.
    expect($this->user->follows()->count())->toBe(1);
});

it('chiede di essere avvisato quando tornano i posti e quando aprono le prenotazioni, dal sito e dall app', function (): void {
    $esaurita = occurrenceAtLocal($this->city, $this->category, '2026-11-28 21:00', null, ['status' => 'sold_out'], ['title' => 'Concerto esaurito']);
    $inArrivo = occurrenceAtLocal($this->city, $this->category, '2026-12-05 21:00', null, [
        'booking_enabled' => true, 'booking_opens_at' => localInstant($this->city, '2026-11-20 10:00')->utc(),
    ], ['title' => 'Prenotazioni in arrivo']);
    $inArrivo->event->venue->update(['ticketing_enabled' => true]);

    $this->actingAs($this->user);
    // Posti esauriti: dal sito chiede l'avviso, poi ci ripensa, poi lo richiede dall'app.
    $this->get(route('events.show', $esaurita->event))->assertOk()->assertSee(__('ticketing.availability.subscribe'));
    $this->from(route('events.show', $esaurita->event))->post('/avvisi-disponibilita/'.$esaurita->id)
        ->assertRedirect(route('events.show', $esaurita->event))->assertSessionHas('status', __('ticketing.availability.stored'));
    $this->get(route('events.show', $esaurita->event))->assertOk()->assertSee(__('ticketing.availability.active'));
    expect($this->getJson('/api/v1/me/availability-alerts')->assertOk()->json('data'))->toBe([$esaurita->id]);
    $this->delete('/avvisi-disponibilita/'.$esaurita->id)->assertSessionHas('status', __('ticketing.availability.removed'));
    expect($this->getJson('/api/v1/me/availability-alerts')->json('data'))->toBe([]);
    $this->postJson('/api/v1/me/availability-alerts/'.$esaurita->id)->assertCreated()->assertJsonPath('data.occurrence_id', $esaurita->id);

    // Si liberano posti: un avviso solo, e la richiesta si chiude.
    $esaurita->update(['status' => 'scheduled']);
    expect(ScheduledNotification::query()->ofType('tickets_available')->where('user_id', $this->user->id)->count())->toBe(1)
        ->and(AvailabilityAlert::query()->count())->toBe(0);

    // Prenotazioni non ancora aperte: avviso dal sito, letto dall'app.
    $this->get(route('events.show', $inArrivo->event))->assertOk()->assertSee(__('ticketing.opening.subscribe'));
    $this->post('/avvisi-apertura/'.$inArrivo->id)->assertSessionHas('status', __('ticketing.opening.stored'));
    $this->get(route('events.show', $inArrivo->event))->assertOk()->assertSee(__('ticketing.opening.active'));
    $this->delete('/avvisi-apertura/'.$inArrivo->id)->assertSessionHas('status', __('ticketing.opening.removed'));
    $this->postJson('/api/v1/me/opening-alerts/'.$inArrivo->id)->assertCreated()->assertJsonPath('data.occurrence_id', $inArrivo->id);
    $this->deleteJson('/api/v1/me/opening-alerts/'.$inArrivo->id)->assertNoContent();
    expect($this->getJson('/api/v1/me/opening-alerts')->json('data'))->toBe([]);
    $this->postJson('/api/v1/me/opening-alerts/'.$inArrivo->id)->assertCreated();
    expect($this->getJson('/api/v1/me/opening-alerts')->assertOk()->json('data'))->toBe([$inArrivo->id])
        ->and(BookingOpeningAlert::query()->count())->toBe(1);

    $this->artisan('ticketing:announce-openings')->assertSuccessful();
    expect(ScheduledNotification::query()->ofType('bookings_open')->count())->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:05', 'UTC'));
    $this->artisan('ticketing:announce-openings')->assertSuccessful();
    expect(ScheduledNotification::query()->ofType('bookings_open')->sole()->user_id)->toBe($this->user->id)
        ->and(BookingOpeningAlert::query()->count())->toBe(0);
    expect($this->getJson('/api/v1/me/opening-alerts')->json('data'))->toBe([]);
});

it('chiede di sapere quando torna un evento finito, dal sito, e lo annulla dall app dopo il primo avviso', function (): void {
    $proLoco = Organizer::create(['name' => 'Pro Loco di Brusegana', 'city_id' => $this->city->id, 'is_active' => true]);
    $sagra = occurrenceAtLocal($this->city, $this->category, '2026-06-10 18:00', event: ['title' => 'Sagra del Bisi 2026', 'organizer_id' => $proLoco->id]);

    $this->actingAs($this->user)->get(route('events.show', $sagra->event))->assertOk()->assertSee(__('events.edition_alert.subscribe'));
    $this->from(route('events.show', $sagra->event))->post('/avvisi-edizione/'.$sagra->event_id)
        ->assertRedirect(route('events.show', $sagra->event))->assertSessionHas('status', __('events.edition_alert.stored'));
    $this->get(route('events.show', $sagra->event))->assertOk()->assertSee(__('events.edition_alert.active'));
    expect($this->getJson('/api/v1/me/edition-alerts')->assertOk()->json('data'))->toBe([$sagra->event_id]);

    // Un evento diverso dello stesso organizzatore non è la nuova edizione.
    $this->travelTo(now()->addDay());
    occurrenceAtLocal($this->city, $this->category, '2027-03-01 18:00', event: ['title' => 'Festa di primavera', 'organizer_id' => $proLoco->id]);
    $this->artisan('editions:notify')->assertSuccessful();
    expect(ScheduledNotification::query()->ofType('next_edition')->count())->toBe(0);

    $nuova = occurrenceAtLocal($this->city, $this->category, '2027-06-09 18:00', event: ['title' => 'Sagra del Bisi 2027', 'organizer_id' => $proLoco->id]);
    $this->artisan('editions:notify')->assertSuccessful();
    $avviso = ScheduledNotification::query()->ofType('next_edition')->sole();
    expect($avviso->user_id)->toBe($this->user->id)
        ->and($avviso->notifiable_id)->toBe($nuova->id)
        ->and(EditionAlert::query()->count())->toBe(0);

    // Lo richiede dall'app e lo annulla, poi lo stesso dal sito: nessuna richiesta resta aperta.
    $this->postJson('/api/v1/me/edition-alerts/'.$sagra->event_id)->assertCreated()->assertJsonPath('data.event_id', $sagra->event_id);
    $this->deleteJson('/api/v1/me/edition-alerts/'.$sagra->event_id)->assertNoContent();
    expect($this->getJson('/api/v1/me/edition-alerts')->json('data'))->toBe([]);
    $this->post('/avvisi-edizione/'.$sagra->event_id)->assertSessionHas('status', __('events.edition_alert.stored'));
    $this->delete('/avvisi-edizione/'.$sagra->event_id)->assertSessionHas('status', __('events.edition_alert.removed'));
    expect(EditionAlert::query()->count())->toBe(0);
});

it('rifiuta gli avvisi a ospiti e account da confermare, su date non pubbliche o quando non hanno senso', function (): void {
    $disponibile = occurrenceAtLocal($this->city, $this->category, '2026-11-28 21:00');
    $bozza = occurrenceAtLocal($this->city, $this->category, '2026-11-28 21:00', null, ['status' => 'sold_out'], ['status' => EventStatus::Draft, 'published_at' => null]);
    $aperta = occurrenceAtLocal($this->city, $this->category, '2026-11-29 21:00', null, ['booking_enabled' => true]);
    $aperta->event->venue->update(['ticketing_enabled' => true]);
    $esauritaPassata = occurrenceAtLocal($this->city, $this->category, '2026-11-01 21:00', null, ['status' => 'sold_out']);

    $this->post('/avvisi-disponibilita/'.$disponibile->id)->assertRedirect(route('login'));
    $this->postJson('/api/v1/me/availability-alerts/'.$disponibile->id)->assertUnauthorized();
    $this->getJson('/api/v1/me/edition-alerts')->assertUnauthorized();

    $nuovo = User::factory()->unverified()->create();
    $this->actingAs($nuovo)->postJson('/api/v1/me/opening-alerts/'.$aperta->id)->assertForbidden()->assertJsonPath('error.code', 'EMAIL_VERIFICATION_REQUIRED');

    $this->actingAs($this->user);
    // Non è esaurita, è passata, o non è pubblica.
    $this->postJson('/api/v1/me/availability-alerts/'.$disponibile->id)->assertUnprocessable();
    $this->postJson('/api/v1/me/availability-alerts/'.$esauritaPassata->id)->assertUnprocessable();
    $this->postJson('/api/v1/me/availability-alerts/'.$bozza->id)->assertNotFound();
    $this->postJson('/api/v1/me/availability-alerts/999999')->assertNotFound();
    // Le prenotazioni sono già aperte.
    $this->postJson('/api/v1/me/opening-alerts/'.$aperta->id)->assertUnprocessable();
    // L'evento ha ancora date in programma, o non è pubblico.
    $this->from(route('events.show', $disponibile->event))->post('/avvisi-edizione/'.$disponibile->event_id)->assertSessionHasErrors('event');
    $this->postJson('/api/v1/me/edition-alerts/'.$bozza->event_id)->assertNotFound();

    expect(AvailabilityAlert::query()->count())->toBe(0)
        ->and(BookingOpeningAlert::query()->count())->toBe(0)
        ->and(EditionAlert::query()->count())->toBe(0);

    // Annullare l'avviso di un'altra persona non tocca il suo.
    $altra = User::factory()->create();
    AvailabilityAlert::query()->create(['user_id' => $altra->id, 'occurrence_id' => $esauritaPassata->id]);
    $this->deleteJson('/api/v1/me/availability-alerts/'.$esauritaPassata->id)->assertNoContent();
    expect(AvailabilityAlert::query()->where('user_id', $altra->id)->count())->toBe(1);
});
