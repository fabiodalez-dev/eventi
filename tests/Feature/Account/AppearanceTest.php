<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\PageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    testCity();
});

it('follows the system by default and exposes the three choices to visitors', function (): void {
    $this->get('/aspetto')->assertOk()->assertSee('data-theme-auto', false)->assertSee(__('account.appearance.options.light.label'))
        ->assertSee('data-appearance-choice="system"', false);
    expect(User::factory()->create()->fresh()->appearance)->toBeNull();
    $this->patchJson('/aspetto', ['appearance' => 'light'])->assertUnauthorized();
});

it('persists only the authenticated users theme and renders it on a new request', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($user)->patchJson('/aspetto', ['appearance' => 'light', 'id' => $other->id, 'name' => 'changed'])
        ->assertOk()->assertJsonPath('appearance', 'light');
    expect($user->fresh()->appearance)->toBe('light')
        ->and($user->fresh()->name)->toBe($user->name)
        ->and($other->fresh()->appearance)->toBeNull();
    $this->actingAs($user->fresh())->get('/aspetto')->assertOk()->assertSee('data-theme="light"', false)
        ->assertSee('data-appearance-choice="light" aria-pressed="true"', false);
    $this->actingAs($other)->get('/aspetto')->assertOk()->assertSee('data-theme-auto', false)
        ->assertSee('data-appearance-choice="system" aria-pressed="true"', false);
});

it('rejects unsupported themes without overwriting a saved choice', function (): void {
    $user = User::factory()->create(['appearance' => 'light']);
    $this->actingAs($user)->patchJson('/aspetto', ['appearance' => 'sepia'])->assertUnprocessable();
    $this->patchJson('/aspetto', [])->assertUnprocessable();
    expect($user->fresh()->appearance)->toBe('light');
});

it('saves the system choice as no preference, from the script and from the plain form', function (): void {
    $user = User::factory()->create(['appearance' => 'dark']);
    $this->actingAs($user)->patchJson('/aspetto', ['appearance' => 'system'])->assertOk()->assertJsonPath('appearance', null);
    expect($user->fresh()->appearance)->toBeNull();

    $user->forceFill(['appearance' => 'light'])->save();
    $this->actingAs($user->fresh())->patchJson('/aspetto', ['appearance' => null])->assertOk()->assertJsonPath('appearance', null);
    expect($user->fresh()->appearance)->toBeNull();

    $user->forceFill(['appearance' => 'light'])->save();
    $this->actingAs($user->fresh())->patch('/aspetto', ['appearance' => 'system'])->assertRedirect('/aspetto');
    expect($user->fresh()->appearance)->toBeNull();

    /* Senza preferenza la pagina lo dice e lascia decidere allo script del layout. */
    $this->actingAs($user->fresh())->get('/aspetto')->assertOk()->assertSee('data-theme-auto', false)
        ->assertSee('data-appearance-choice="system" aria-pressed="true"', false);
    $this->get('/il-mio-profilo')->assertOk()->assertSee(__('account.appearance.current.system'));
});

it('supports the non javascript profile form and switching back to dark', function (): void {
    $user = User::factory()->create(['appearance' => 'light']);
    $this->actingAs($user)->patch('/aspetto', ['appearance' => 'dark'])->assertRedirect('/aspetto');
    expect($user->fresh()->appearance)->toBe('dark');
});

it('never puts an authenticated appearance into the public page cache', function (): void {
    config(['page_cache.enabled' => true]);
    $this->get('/')->assertOk()->assertSee('data-theme="dark"', false);
    $user = User::factory()->create(['appearance' => 'light']);
    $this->actingAs($user)->get('/')->assertOk()->assertSee('data-theme="light"', false);
    auth()->forgetGuards();
    $this->get('/')->assertOk()->assertSee('data-theme="dark"', false);
});

it('shares the same preference with the native API and validates native updates', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $this->patchJson('/api/v1/me', ['appearance' => 'light'])->assertOk()->assertJsonPath('data.appearance', 'light');
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.appearance', 'light');
    $this->patchJson('/api/v1/me', ['appearance' => 'invalid'])->assertUnprocessable();
    expect($user->fresh()->appearance)->toBe('light');
    /* `null` è «segui il sistema», come sul sito: lo dice `appearance_choice`;
       `appearance` resta una stringa per l'app in circolazione. */
    $this->patchJson('/api/v1/me', ['appearance' => null])->assertOk()
        ->assertJsonPath('data.appearance_choice', null)
        ->assertJsonPath('data.appearance', 'dark');
    expect($user->fresh()->appearance)->toBeNull();
});

it('declares appearance storage as necessary in the public cookie policy', function (): void {
    $this->seed(PageSeeder::class);
    $this->get('/pagine/cookie')->assertOk()->assertSee('incitta_appearance')->assertSee('Aspetto e preferenza del tema');
    $this->assertDatabaseHas('cookie_declarations', ['name' => 'incitta_appearance', 'category' => 'necessary']);
});
