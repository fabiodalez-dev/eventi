<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Venue;
use Illuminate\Support\Facades\Gate;
use Tests\Support\VenueIsolationScenario;

/**
 * §18 scenario F: «il locale A non può leggere, modificare o cancellare
 * eventi del locale B, nemmeno manipolando URL o ID direttamente».
 *
 * Dove conta, i test non raggiungono il modello attraverso le relazioni
 * dell'utente: lo ricostruiscono come farebbe una richiesta HTTP — dalla
 * chiave scritta nell'URL o nel corpo del form — perché è da lì che arriva
 * l'attacco.
 */
it('nega al referente del locale A ogni azione sugli eventi del locale B', function (): void {
    $s = VenueIsolationScenario::make();
    $ownerA = $s->ownerA;

    // Un evento pubblicato è pubblico: leggerlo è lecito per chiunque.
    // Ciò che non deve essere lecito è toccarlo.
    expect(Gate::forUser($ownerA)->allows('view', $s->draftEventB))->toBeFalse()
        ->and(Gate::forUser($ownerA)->allows('update', $s->publishedEventB))->toBeFalse()
        ->and(Gate::forUser($ownerA)->allows('update', $s->draftEventB))->toBeFalse()
        ->and(Gate::forUser($ownerA)->allows('delete', $s->publishedEventB))->toBeFalse()
        ->and(Gate::forUser($ownerA)->allows('restore', $s->publishedEventB))->toBeFalse()
        ->and(Gate::forUser($ownerA)->allows('forceDelete', $s->publishedEventB))->toBeFalse()
        ->and(Gate::forUser($ownerA)->allows('publish', $s->draftEventB))->toBeFalse()
        ->and(Gate::forUser($ownerA)->allows('moderate', $s->publishedEventB))->toBeFalse();
});

it('consente al referente del locale A le stesse azioni sui propri eventi', function (): void {
    $s = VenueIsolationScenario::make();
    $ownerA = $s->ownerA;

    expect(Gate::forUser($ownerA)->allows('view', $s->draftEventA))->toBeTrue()
        ->and(Gate::forUser($ownerA)->allows('update', $s->draftEventA))->toBeTrue()
        ->and(Gate::forUser($ownerA)->allows('delete', $s->draftEventA))->toBeTrue()
        ->and(Gate::forUser($ownerA)->allows('create', [Event::class, $s->venueA]))->toBeTrue();
});

it('nega al referente del locale A ogni azione sul locale B', function (): void {
    $s = VenueIsolationScenario::make();
    $ownerA = $s->ownerA;
    $venueB = $s->venueB;

    expect(Gate::forUser($ownerA)->allows('update', $venueB))->toBeFalse()
        ->and(Gate::forUser($ownerA)->allows('delete', $venueB))->toBeFalse()
        ->and(Gate::forUser($ownerA)->allows('restore', $venueB))->toBeFalse()
        ->and(Gate::forUser($ownerA)->allows('forceDelete', $venueB))->toBeFalse()
        ->and(Gate::forUser($ownerA)->allows('manageCollaborators', $venueB))->toBeFalse()
        ->and(Gate::forUser($ownerA)->allows('viewSensitiveData', $venueB))->toBeFalse()
        ->and(Gate::forUser($ownerA)->allows('moderate', $venueB))->toBeFalse();
});

it('nega la lettura di un locale non approvato di cui non si fa parte', function (): void {
    $s = VenueIsolationScenario::make();

    // Il locale approvato è una scheda pubblica; è la condizione non ancora
    // approvata a essere riservata a chi lo gestisce e allo staff.
    $pendingVenue = Venue::factory()->pending()->create([
        'city_id' => $s->city->getKey(),
        'name' => 'Sala Naviglio',
    ]);

    expect(Gate::forUser($s->ownerA)->allows('view', $pendingVenue))->toBeFalse()
        ->and(Gate::forUser($s->admin)->allows('view', $pendingVenue))->toBeTrue()
        ->and(Gate::forUser($s->ownerA)->allows('view', $s->venueB))->toBeTrue();
});

it('nega al referente del locale A le occorrenze degli eventi del locale B', function (): void {
    $s = VenueIsolationScenario::make();
    $ownerA = $s->ownerA;

    expect(Gate::forUser($ownerA)->allows('update', $s->occurrenceB))->toBeFalse()
        ->and(Gate::forUser($ownerA)->allows('delete', $s->occurrenceB))->toBeFalse()
        ->and(Gate::forUser($ownerA)->allows('create', [EventOccurrence::class, $s->publishedEventB]))->toBeFalse()
        ->and(Gate::forUser($ownerA)->allows('update', $s->occurrenceA))->toBeTrue()
        ->and(Gate::forUser($ownerA)->allows('delete', $s->occurrenceA))->toBeTrue();
});

it('nega l accesso quando il locale B arriva da uno slug manipolato nell URL', function (): void {
    $s = VenueIsolationScenario::make();
    $ownerA = $s->ownerA;

    // `resolveRouteBinding()` è ciò che Laravel esegue davvero sul segmento
    // di URL: il modello nasce dalla chiave scritta a mano, non da una
    // relazione dell'utente autenticato.
    $venueFromUrl = (new Venue)->resolveRouteBinding($s->venueB->getRouteKey());
    $eventFromUrl = (new Event)->resolveRouteBinding($s->draftEventB->getRouteKey());

    expect($venueFromUrl)->not->toBeNull()
        ->and($eventFromUrl)->not->toBeNull()
        ->and(Gate::forUser($ownerA)->allows('update', $venueFromUrl))->toBeFalse()
        ->and(Gate::forUser($ownerA)->allows('manageCollaborators', $venueFromUrl))->toBeFalse()
        ->and(Gate::forUser($ownerA)->allows('view', $eventFromUrl))->toBeFalse()
        ->and(Gate::forUser($ownerA)->allows('update', $eventFromUrl))->toBeFalse()
        ->and(Gate::forUser($ownerA)->allows('delete', $eventFromUrl))->toBeFalse();
});

it('nega l accesso quando gli identificatori del locale B arrivano grezzi', function (): void {
    $s = VenueIsolationScenario::make();
    $ownerA = $s->ownerA;

    // Chiavi numeriche prese dall'URL o dal corpo di una richiesta e risolte
    // senza alcun vincolo di appartenenza: è il caso che lo scenario F chiama
    // «manipolando ID direttamente».
    $venueB = Venue::query()->findOrFail($s->venueB->getKey());
    $eventB = Event::query()->findOrFail($s->draftEventB->getKey());
    $occurrenceB = EventOccurrence::query()->findOrFail($s->occurrenceB->getKey());

    expect(Gate::forUser($ownerA)->allows('update', $venueB))->toBeFalse()
        ->and(Gate::forUser($ownerA)->allows('update', $eventB))->toBeFalse()
        ->and(Gate::forUser($ownerA)->allows('delete', $eventB))->toBeFalse()
        ->and(Gate::forUser($ownerA)->allows('update', $occurrenceB))->toBeFalse()
        ->and(Gate::forUser($ownerA)->allows('delete', $occurrenceB))->toBeFalse()
        ->and(Gate::forUser($ownerA)->allows('create', [Event::class, $venueB]))->toBeFalse()
        ->and(Gate::forUser($ownerA)->allows('create', [EventOccurrence::class, $eventB]))->toBeFalse();
});

it('nega la gestione di un evento con il venue_id del locale B forzato nel form', function (): void {
    $s = VenueIsolationScenario::make();

    // Payload contraffatto: l'evento porta con sé il `venue_id` di un locale
    // altrui, senza passare da alcuna relazione.
    $forged = new Event(['title' => 'Evento intestato a un locale altrui']);
    $forged->venue_id = $s->venueB->getKey();
    $forged->city_id = $s->city->getKey();

    expect(Gate::forUser($s->ownerA)->allows('update', $forged))->toBeFalse()
        ->and(Gate::forUser($s->ownerA)->allows('delete', $forged))->toBeFalse();
});

it('nega al referente di un locale gli eventi senza locale registrato', function (): void {
    $s = VenueIsolationScenario::make();

    // Un evento con `venue_id` nullo non ha pivot da verificare: resta
    // gestibile solo dalla redazione, mai da un gestore di locale.
    $freeEvent = Event::factory()->published()->withoutVenue()->create([
        'city_id' => $s->city->getKey(),
        'category_id' => $s->category->getKey(),
        'title' => 'Corteo in Prato della Valle',
    ]);

    expect(Gate::forUser($s->ownerA)->allows('update', $freeEvent))->toBeFalse()
        ->and(Gate::forUser($s->ownerB)->allows('update', $freeEvent))->toBeFalse()
        ->and(Gate::forUser($s->admin)->allows('update', $freeEvent))->toBeTrue();
});

it('isola i due referenti in modo simmetrico', function (): void {
    $s = VenueIsolationScenario::make();

    // L'isolamento non è una proprietà del locale A: vale in entrambe le
    // direzioni, altrimenti sarebbe un caso particolare e non una regola.
    expect(Gate::forUser($s->ownerB)->allows('update', $s->draftEventA))->toBeFalse()
        ->and(Gate::forUser($s->ownerB)->allows('delete', $s->occurrenceA))->toBeFalse()
        ->and(Gate::forUser($s->ownerB)->allows('update', $s->venueA))->toBeFalse()
        ->and(Gate::forUser($s->ownerB)->allows('update', $s->draftEventB))->toBeTrue();
});
