<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

/*
 * La vista degli elenchi sul telefono sta nel profilo, come il tema: scelta
 * sul sito la ritrova l'app, scelta nell'app la ritrova il sito.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    testCity();
});

it('salva la vista solo per chi è collegato, e solo una delle tre', function (): void {
    $this->patchJson('/aspetto/vista-elenchi', ['card_layout' => 'grid'])->assertUnauthorized();

    $user = User::factory()->create();
    expect($user->fresh()->card_layout)->toBeNull();

    $this->actingAs($user)->patchJson('/aspetto/vista-elenchi', ['card_layout' => 'grid'])
        ->assertOk()->assertJsonPath('card_layout', 'grid')->assertHeader('Cache-Control', 'no-store, private');
    expect($user->fresh()->card_layout)->toBe('grid');

    $this->actingAs($user)->patchJson('/aspetto/vista-elenchi', ['card_layout' => 'mosaico'])->assertUnprocessable();
    $this->actingAs($user)->patchJson('/aspetto/vista-elenchi', [])->assertUnprocessable();
    expect($user->fresh()->card_layout)->toBe('grid');
});

it('scrive nella pagina la vista del profilo, e per l\'ospite lascia fare al browser', function (): void {
    $guest = $this->get('/')->assertOk()->getContent();
    expect($guest)->toContain('data-card-layout="full"')
        ->not->toContain('data-card-layout-saved')
        ->not->toContain('data-card-layout-url');

    $user = User::factory()->create();
    $none = $this->actingAs($user)->get('/')->assertOk()->getContent();
    expect($none)->toContain('data-card-layout="full"', 'data-card-layout-url="'.route('appearance.card-layout').'"')
        ->not->toContain('data-card-layout-saved');

    $user->forceFill(['card_layout' => 'list'])->save();
    $chosen = $this->actingAs($user)->get('/')->assertOk()->getContent();
    expect($chosen)->toContain('data-card-layout="list"', 'data-card-layout-saved');
});

it('legge e cambia la vista dall\'app, anche per tornare a nessuna scelta', function (): void {
    $user = User::factory()->create(['card_layout' => 'grid']);
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.card_layout', 'grid');
    $this->patchJson('/api/v1/me', ['card_layout' => 'list'])->assertOk()->assertJsonPath('data.card_layout', 'list');
    expect($user->fresh()->card_layout)->toBe('list');

    $this->patchJson('/api/v1/me', ['card_layout' => 'colonne'])->assertUnprocessable();
    $this->patchJson('/api/v1/me', ['card_layout' => null])->assertOk()->assertJsonPath('data.card_layout', null);
    expect($user->fresh()->card_layout)->toBeNull();

    /* Una modifica d'altro non tocca la vista. */
    $user->forceFill(['card_layout' => 'grid'])->save();
    $this->patchJson('/api/v1/me', ['name' => 'Nome nuovo'])->assertOk()->assertJsonPath('data.card_layout', 'grid');
});
