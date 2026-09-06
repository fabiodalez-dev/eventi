<?php

use App\DTOs\ConsentState;
use App\Enums\ConsentCategory;
use App\Filament\Admin\Pages\NotificationTexts;
use App\Filament\Admin\Resources\ConsentScripts\ConsentScriptResource;
use App\Filament\Admin\Resources\ConsentScripts\Pages\ManageConsentScripts;
use App\Models\ConsentScript;
use App\Models\NotificationText;
use App\Models\User;
use App\Support\Consent;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

it('only renders enabled scripts in categories accepted by the visitor', function (): void {
    foreach (ConsentCategory::cases() as $category) {
        ConsentScript::create(['name' => $category->value, 'category' => $category, 'enabled' => true, 'src' => 'https://example.test/'.$category->value.'.js']);
    }
    ConsentScript::create(['name' => 'disabled', 'category' => ConsentCategory::Necessary, 'enabled' => false, 'src' => 'https://example.test/disabled.js']);
    $html = view('components.consent-scripts')->render();
    expect($html)->toContain('necessary.js')->not->toContain('statistics.js', 'marketing.js', 'disabled.js');
    app(Consent::class)->remember(new ConsentState('test', app(Consent::class)->version(), ['statistics' => true, 'marketing' => false]));
    expect(view('components.consent-scripts')->render())->toContain('statistics.js')->not->toContain('marketing.js');
    app(Consent::class)->remember(new ConsentState('test', app(Consent::class)->version(), ['statistics' => false, 'marketing' => false]));
    expect(view('components.consent-scripts')->render())->not->toContain('statistics.js', 'marketing.js');
});

it('restricts script configuration to administrators and invalidates page caches on change', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->create();
    $user->assignRole('moderator');
    expect(Gate::forUser($user)->allows('create', ConsentScript::class))->toBeFalse();
    $this->actingAs($user)->get(ConsentScriptResource::getUrl())->assertForbidden();
    $user->assignRole('admin');
    $this->get(ConsentScriptResource::getUrl())->assertOk()->assertSee(__('consent_scripts.title'));
    $script = ConsentScript::create(['name' => 'test', 'category' => ConsentCategory::Statistics, 'code' => 'window.test = true;']);
    $version = cache('consent_scripts_revision');
    $script->update(['enabled' => true]);
    expect(cache('consent_scripts_revision'))->not->toBe($version);
});

it('shows placeholder insertion and previews and rejects unsupported placeholders before saving', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin)->get(NotificationTexts::getUrl())->assertOk()->assertSee('Inserisci')->assertSee('Esempio con dati dimostrativi');
    Livewire::test(NotificationTexts::class)
        ->set('data.notifications__moved__subject', 'Nuovo orario :inventato')
        ->call('salva')->assertHasErrors(['data.notifications__moved__subject']);
    expect(NotificationText::count())->toBe(0);
});

it('validates script form input and leaves new scripts disabled', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);
    Livewire::test(ManageConsentScripts::class)->callAction('create', data: [
        'name' => 'Statistics test', 'category' => 'statistics', 'sort_order' => 0,
        'src' => 'https://example.test/stats.js', 'code' => null, 'enabled' => false,
    ])->assertHasNoActionErrors();
    expect(ConsentScript::firstOrFail()->enabled)->toBeFalse();
    Livewire::test(ManageConsentScripts::class)->callAction('create', data: [
        'name' => 'Bad code', 'category' => 'statistics', 'sort_order' => 0,
        'src' => null, 'code' => '<script>alert(1)</script>', 'enabled' => false,
    ])->assertHasActionErrors(['code']);
    expect(ConsentScript::count())->toBe(1);
});
