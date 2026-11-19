<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    testCity();
});

it('fa nascere i profili senza un tema scelto', function (): void {
    expect(User::factory()->create()->fresh()->appearance)->toBeNull();
});

it('copia nel profilo il tema scelto da ospite al primo accesso', function (): void {
    $user = User::factory()->create(['appearance' => null]);

    $this->withUnencryptedCookie('incitta_appearance', 'light')
        ->post('/accedi', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect();

    expect($user->fresh()->appearance)->toBe('light');

    $this->get('/aspetto')
        ->assertOk()
        ->assertSee('data-theme="light"', false)
        ->assertDontSee('data-theme-auto', false);
});

it('non sovrascrive la scelta fatta dal profilo', function (): void {
    $user = User::factory()->create(['appearance' => 'dark']);

    $this->withUnencryptedCookie('incitta_appearance', 'light')
        ->post('/accedi', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect();

    expect($user->fresh()->appearance)->toBe('dark');

    $this->get('/aspetto')->assertSee('data-theme="dark"', false)->assertDontSee('data-theme-auto', false);
});

it('ignora un cookie con un valore inventato', function (): void {
    $user = User::factory()->create(['appearance' => null]);

    $this->withUnencryptedCookie('incitta_appearance', 'rosa')
        ->post('/accedi', ['email' => $user->email, 'password' => 'password']);

    expect($user->fresh()->appearance)->toBeNull();
});

it('tratta il profilo senza scelta come un ospite: browser, poi sistema', function (): void {
    $user = User::factory()->create(['appearance' => null]);

    $this->actingAs($user)->get('/aspetto')
        ->assertOk()
        ->assertSee('data-theme-auto', false)
        ->assertSee("'themeAuto' in root.dataset", false);

    $this->actingAs($user)->withUnencryptedCookie('incitta_appearance', 'light')->get('/aspetto')
        ->assertSee('data-theme="light"', false);
});

it('riprende il tema del browser anche alla registrazione', function (): void {
    $user = User::factory()->create(['appearance' => null]);
    app()->instance('request', Request::create('/registrati', 'POST', [], ['incitta_appearance' => 'dark']));

    event(new Registered($user));

    expect($user->fresh()->appearance)->toBe('dark');
});

it('espone e accetta il tema vuoto nell\'API, lasciando `appearance` una stringa per l\'app in circolazione', function (): void {
    $user = User::factory()->create(['appearance' => null]);
    Sanctum::actingAs($user);

    // L'app in circolazione decodifica `appearance` come stringa non nullabile.
    $this->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('data.appearance', 'dark')
        ->assertJsonPath('data.appearance_choice', null);
    $this->patchJson('/api/v1/me', ['appearance' => 'light'])->assertOk()
        ->assertJsonPath('data.appearance', 'light')
        ->assertJsonPath('data.appearance_choice', 'light');
    $this->patchJson('/api/v1/me', ['appearance' => null])->assertOk()
        ->assertJsonPath('data.appearance', 'dark')
        ->assertJsonPath('data.appearance_choice', null);
    $this->patchJson('/api/v1/me', ['appearance' => 'system'])->assertUnprocessable();

    expect($user->fresh()->appearance)->toBeNull();
});

it('nel login e nello scambio dell\'API `appearance` non è mai null', function (): void {
    $user = User::factory()->create(['appearance' => null, 'password' => 'una-password-lunga']);

    $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'una-password-lunga', 'device_name' => 'test'])->assertOk();
    expect($login->json('data.user.appearance'))->toBeString()->toBe('dark')
        ->and($login->json('data.user'))->toHaveKey('appearance_choice')
        ->and($login->json('data.user.appearance_choice'))->toBeNull();
});
