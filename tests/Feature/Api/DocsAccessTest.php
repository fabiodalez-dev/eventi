<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;

/**
 * Chi vede la documentazione OpenAPI su `/docs/api` (D35).
 *
 * Il pacchetto lascia passare chiunque in ambiente `local`; in ogni altro
 * ambiente — collaudo e produzione comprese, e questa suite gira in `testing` —
 * interroga il cancello `viewApiDocs`, che apre allo **staff editoriale
 * autenticato** e a nessun altro.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('nega la documentazione a chi non è collegato', function (string $url): void {
    $this->get($url)->assertForbidden();
})->with([
    'la pagina' => ['/docs/api'],
    /* Il documento OpenAPI vale piu' della pagina che lo mostra: e' quello che
       si scarica e si dà in pasto a un generatore di client. */
    'il documento OpenAPI' => ['/docs/api.json'],
]);

it('nega la documentazione a una persona senza ruolo di redazione', function (string $url): void {
    $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
})->with([
    'la pagina' => ['/docs/api'],
    'il documento OpenAPI' => ['/docs/api.json'],
]);

it('apre la documentazione allo staff editoriale', function (string $role): void {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get('/docs/api')->assertOk();
})->with([
    'amministratore' => [UserRole::Admin->value],
    'super amministratore' => [UserRole::SuperAdmin->value],
    'moderatore' => [UserRole::Moderator->value],
]);

it('risponde alla domanda anche fuori da una richiesta HTTP', function (): void {
    // Il cancello è dichiarato una volta e vale ovunque: un comando o un
    // pannello che volesse chiedere la stessa cosa ottiene la stessa risposta.
    $staff = User::factory()->create();
    $staff->assignRole(UserRole::Moderator->value);

    expect(Gate::forUser($staff)->allows('viewApiDocs'))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('viewApiDocs'))->toBeFalse()
        ->and(Gate::forUser(null)->allows('viewApiDocs'))->toBeFalse();
});
