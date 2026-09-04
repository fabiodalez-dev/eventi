<?php

declare(strict_types=1);

use App\Enums\FollowableType;
use App\Models\Follow;
use App\Models\User;
use App\Models\Venue;

/**
 * Seguire un locale dalla sua scheda.
 *
 * Il pulsante era spento, con una nota che diceva «funzionerà quando
 * arriveranno gli account». Gli account sono arrivati, e il feed usava già i
 * follow per scegliere cosa mostrare: mancava soltanto il gesto per crearne
 * uno — rotte, controller, azioni e modello erano al loro posto da tempo.
 */
beforeEach(function (): void {
    /* La scheda di un locale si apre solo se il locale sta nella citta'
       attiva: senza `city_id` la pagina risponde 404 e il test sembra
       parlare di rotte mentre parla di dati. */
    $this->citta = testCity();
    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->citta->getKey()]);
    $this->utente = User::factory()->create();
});

it('segue un locale e lo smette di seguire', function (): void {
    $this->actingAs($this->utente)
        ->post(route('account.follows.store'), [
            'type' => FollowableType::Venue->value,
            'id' => $this->venue->getKey(),
        ])
        ->assertRedirect();

    expect($this->utente->followedIds(FollowableType::Venue))->toContain($this->venue->getKey());

    $this->actingAs($this->utente)
        ->delete(route('account.follows.destroy', [
            'type' => FollowableType::Venue->value,
            'id' => $this->venue->getKey(),
        ]))
        ->assertRedirect();

    expect($this->utente->fresh()->followedIds(FollowableType::Venue))->not->toContain($this->venue->getKey());
});

it('non segue due volte lo stesso locale', function (): void {
    foreach ([1, 2] as $volta) {
        $this->actingAs($this->utente)->post(route('account.follows.store'), [
            'type' => FollowableType::Venue->value,
            'id' => $this->venue->getKey(),
        ]);
    }

    /* Due righe per lo stesso locale non romperebbero niente di visibile, e
       proprio per questo si accumulerebbero: il feed conterebbe due volte lo
       stesso posto e nessuno saprebbe perché. */
    expect(Follow::query()->count())->toBe(1);
});

it('mostra il pulsante acceso a chi gia segue', function (): void {
    $this->actingAs($this->utente)->post(route('account.follows.store'), [
        'type' => FollowableType::Venue->value,
        'id' => $this->venue->getKey(),
    ]);

    $this->actingAs($this->utente)
        ->get(route('venues.show', $this->venue))
        ->assertOk()
        ->assertSee(__('account.follow.following'))
        /* Lo stato si legge anche senza vedere il colore: `aria-pressed` è
           ciò che sente chi usa uno screen reader, e senza di esso il
           pulsante direbbe soltanto «segui» in entrambi i casi. */
        ->assertSee('aria-pressed="true"', false);
});

it('a chi non ha l accesso offre il login, non un pulsante spento', function (): void {
    $risposta = $this->get(route('venues.show', $this->venue));

    $risposta->assertOk()
        ->assertSee(__('account.follow.venue'))
        /* Un pulsante disabilitato è un inganno: sembra un'azione e non lo è.
           Un collegamento al login dice cosa serve per farla. */
        ->assertDontSee('disabled', false);

    expect($risposta->getContent())->toContain(urlencode(route('venues.show', $this->venue)));
});
