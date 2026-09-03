<?php

declare(strict_types=1);

use App\Actions\ModerateVenueAction;
use App\Enums\VenueStatus;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;

/**
 * Cosa succede davvero quando un locale viene sospeso.
 *
 * La domanda nasce da una segnalazione: «non posso disabilitare questa venue
 * una volta abilitata». Il primo sospetto era l'interfaccia — l'azione era
 * sepolta in un menu senza etichetta — ma la verifica ha aperto una questione
 * più seria: **cosa cambia, sul sito, dopo aver premuto quel pulsante.**
 *
 * Una sospensione che non toglie nulla dal sito è un pulsante che mente. Ed è
 * peggio che non averlo: chi lo preme crede di aver agito e smette di
 * guardare.
 *
 * Questi test dicono cosa deve succedere. Sono nati **falliti**, di proposito.
 */
beforeEach(function (): void {
    $this->venue = Venue::factory()->approved()->create();
    $this->referente = User::factory()->create();
    $this->venue->members()->attach($this->referente, ['role' => 'owner']);
});

it('toglie il locale dall elenco pubblico', function (): void {
    app(ModerateVenueAction::class)->suspend($this->venue, 'segnalazioni ripetute');

    expect(Venue::query()->approved()->pluck('id'))
        ->not->toContain($this->venue->getKey());
});

it('conserva la storia dell approvazione', function (): void {
    app(ModerateVenueAction::class)->suspend($this->venue, 'in verifica');

    /* Sospendere non è rifiutare: il locale *era* approvato, e resterà vero
       anche dopo. Cancellare `approved_at` renderebbe la riabilitazione una
       seconda approvazione, perdendo la distinzione fra i due casi. */
    expect($this->venue->fresh()->approved_at)->not->toBeNull()
        ->and($this->venue->fresh()->status)->toBe(VenueStatus::Suspended);
});

it('impedisce al referente di continuare a pubblicare', function (): void {
    app(ModerateVenueAction::class)->suspend($this->venue, 'contenuti inappropriati');

    /* Il caso che rende urgente il resto: se il pannello non guarda lo stato,
       il locale sospeso continua a lavorare come prima e la sospensione è
       una nota interna, non un provvedimento. */
    expect($this->referente->fresh()->canAccessPanel(Filament::getPanel('venue')))
        ->toBeFalse();
});

it('nasconde gli eventi del locale sospeso', function (): void {
    $evento = eventoVisibile($this->venue);

    expect(eventiPubblici($evento))->toContain($evento->getKey());

    app(ModerateVenueAction::class)->suspend($this->venue, 'in verifica');

    /* Un locale fuori dal sito che continua a riempirlo di eventi è la stessa
       contraddizione vista dall'altro lato. */
    expect(eventiPubblici($evento))->not->toContain($evento->getKey());
});

it('rimette tutto al suo posto quando il locale viene riapprovato', function (): void {
    $evento = eventoVisibile($this->venue);
    $azione = app(ModerateVenueAction::class);
    $moderatore = User::factory()->create();

    $azione->suspend($this->venue, 'in verifica');
    $azione->approve($this->venue->fresh(), $moderatore);

    /* Una sospensione è reversibile per definizione: se riapprovare non
       ripristinasse gli eventi, sarebbe una cancellazione lenta. */
    expect(eventiPubblici($evento))->toContain($evento->getKey())
        ->and($this->referente->fresh()->canAccessPanel(Filament::getPanel('venue')))->toBeTrue();
});

/**
 * Gli id degli eventi che il sito pubblico mostrerebbe davvero.
 *
 * Passa dalla stessa query delle pagine — `EventOccurrenceQuery` — invece di
 * interrogare la tabella: un filtro aggiunto al modello e non alla query non
 * proteggerebbe nessuna pagina, e un test sulla tabella non se ne accorgerebbe.
 */
function eventiPubblici(Event $evento): Collection
{
    return EventOccurrenceQuery::for($evento->city)
        ->upcoming()
        ->get()
        ->pluck('event_id');
}

/**
 * Un evento che il sito mostrerebbe davvero: pubblicato, nella citta' del
 * locale, e **con un'occorrenza futura**.
 *
 * L'ultimo requisito non e' un dettaglio. Senza occorrenza l'evento non entra
 * in nessuna pagina, e un test che lo sospende lo trova assente sia prima sia
 * dopo: verde, e privo di significato. E' successo scrivendo questo file, e a
 * smascherarlo e' stato il test della riapprovazione — che pretendeva di
 * ritrovare l'evento e non lo trovava. Per questo ogni prova qui **verifica
 * prima che l'evento sia visibile**, e solo dopo sospende.
 */
function eventoVisibile(Venue $venue): Event
{
    $evento = Event::factory()->published()->create([
        'venue_id' => $venue->getKey(),
        'city_id' => $venue->city_id,
    ]);

    EventOccurrence::factory()->create([
        'event_id' => $evento->getKey(),
        'starts_at' => now()->addWeek(),
        'ends_at' => null,
        'doors_at' => null,
    ]);

    return $evento;
}
