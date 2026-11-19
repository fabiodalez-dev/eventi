<?php

declare(strict_types=1);

use App\Actions\Account\RequestRegistration;
use App\Enums\UserRole;
use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\Users\RegistrationState;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Admin\Widgets\NewRegistrationsWidget;
use App\Filament\Admin\Widgets\ReturnRatesWidget;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Le iscrizioni viste dalla redazione: chi si è appena registrato e non ha
 * ancora confermato deve stare in cima all'elenco, essere isolato da un
 * filtro e contato nel riepilogo. Il caso vero era un account non confermato
 * che l'elenco, ordinato per nome, lasciava dove nessuno guardava.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->travelTo(CarbonImmutable::parse('2026-11-18 21:40:00', 'UTC'));

    $this->admin = User::factory()->create(['name' => 'Amministratrice', 'created_at' => now()->subYear()]);
    $this->admin->assignRole(UserRole::Admin->value);
    $this->actingAs($this->admin);
});

/** Un account nato dalla registrazione vera, con il suo sospeso aperto. */
function admUtentiRegistrato(string $email, ?string $name = null): User
{
    Notification::fake();
    [$user] = app(RequestRegistration::class)->account(['name' => $name, 'email' => $email, 'password' => 'una-password-lunga-123']);

    return $user->fresh();
}

it('mostra in cima l\'account appena registrato e non confermato, anche senza nome', function (): void {
    $vecchio = User::factory()->create(['name' => 'Aaron Primo in ordine alfabetico', 'created_at' => now()->subMonths(3)]);
    $nuovo = admUtentiRegistrato('nuova.iscritta@example.test');

    expect($nuovo->email_claim_pending_at)->not->toBeNull()
        ->and($nuovo->email_verified_at)->toBeNull()
        ->and($nuovo->name)->toBeNull()
        ->and(RegistrationState::for($nuovo))->toBe(RegistrationState::Pending);

    $this->get('/admin/users')->assertOk()
        ->assertSeeInOrder(['nuova.iscritta@example.test', 'Aaron Primo in ordine alfabetico'])
        ->assertSee(__('users.registration.states.pending'))
        ->assertSee(__('users.registration.registered_at'));

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$nuovo, $vecchio, $this->admin], inOrder: true)
        ->assertTableColumnStateSet('registration_state', RegistrationState::Pending, $nuovo)
        ->assertTableColumnStateSet('registration_state', RegistrationState::Confirmed, $vecchio)
        ->assertTableColumnStateSet('name', 'nuova.iscritta@example.test', $nuovo);
});

it('distingue confermato, in attesa e primo accesso da completare', function (): void {
    $confermato = User::factory()->create();
    $inAttesa = User::factory()->unverified()->create();
    $primoAccesso = User::factory()->create(['email_verified_at' => now()]);
    $primoAccesso->forceFill(['email_claim_pending_at' => now()])->save();

    expect(RegistrationState::for($confermato))->toBe(RegistrationState::Confirmed)
        ->and(RegistrationState::for($inAttesa))->toBe(RegistrationState::Pending)
        ->and(RegistrationState::for($primoAccesso->fresh()))->toBe(RegistrationState::FirstAccess);

    Livewire::test(ListUsers::class)
        ->filterTable('registration', RegistrationState::Pending->value)
        ->assertCanSeeTableRecords([$inAttesa])
        ->assertCanNotSeeTableRecords([$confermato, $primoAccesso]);

    Livewire::test(ListUsers::class)
        ->filterTable('registration', RegistrationState::FirstAccess->value)
        ->assertCanSeeTableRecords([$primoAccesso])
        ->assertCanNotSeeTableRecords([$confermato, $inAttesa]);

    Livewire::test(ListUsers::class)
        ->filterTable('registration', RegistrationState::Confirmed->value)
        ->assertCanSeeTableRecords([$confermato, $this->admin])
        ->assertCanNotSeeTableRecords([$inAttesa, $primoAccesso]);
});

it('isola chi deve ancora completare l\'iscrizione con la scheda «In attesa di conferma»', function (): void {
    $confermato = User::factory()->create();
    $registrato = admUtentiRegistrato('in.attesa@example.test', 'Persona In Attesa');

    Livewire::test(ListUsers::class)
        ->set('activeTab', 'pending')
        ->assertCanSeeTableRecords([$registrato])
        ->assertCanNotSeeTableRecords([$confermato, $this->admin]);

    // Il collegamento del riepilogo apre direttamente la scheda.
    $this->get(UserResource::getUrl('index', ['tab' => 'pending']))->assertOk()->assertSee('Persona In Attesa');

    // Dopo la conferma dell'indirizzo l'account esce dalla scheda.
    $registrato->forceFill(['email_verified_at' => now(), 'email_claim_pending_at' => null])->save();
    Livewire::test(ListUsers::class)->set('activeTab', 'pending')->assertCanNotSeeTableRecords([$registrato]);
});

it('non nasconde alcun account non confermato dietro uno scope', function (): void {
    $registrato = admUtentiRegistrato('nascosto@example.test');

    expect(UserResource::getEloquentQuery()->whereKey($registrato->getKey())->exists())->toBeTrue()
        ->and(User::query()->whereKey($registrato->getKey())->exists())->toBeTrue();

    foreach (['all', 'pending', 'users'] as $tab) {
        Livewire::test(ListUsers::class)->set('activeTab', $tab)->assertCanSeeTableRecords([$registrato]);
    }
});

it('conta le iscrizioni nel riepilogo e le elenca dalla più recente', function (): void {
    User::factory()->create(['name' => 'Iscritta da tempo', 'created_at' => now()->subDays(30)]);
    admUtentiRegistrato('ieri@example.test', 'Arrivata Ieri');
    $this->travel(1)->day();
    admUtentiRegistrato('oggi@example.test', 'Arrivata Oggi');

    expect(NewRegistrationsWidget::canView())->toBeTrue();

    Livewire::test(NewRegistrationsWidget::class)
        ->assertOk()
        ->assertSee(__('users.widget.heading'))
        ->assertSee(trans_choice('users.widget.description', 2, ['count' => 2, 'days' => RegistrationState::RECENT_DAYS]))
        ->assertSee(trans_choice('users.widget.pending', 2, ['count' => 2]))
        ->assertSeeInOrder(['Arrivata Oggi', 'Arrivata Ieri', 'Iscritta da tempo'])
        ->assertSee(__('users.registration.states.pending'))
        ->assertSee(UserResource::getUrl('index', ['tab' => 'pending']), escape: false);

    $this->get('/admin')->assertOk()->assertSee(__('users.widget.heading'))->assertSee('Arrivata Oggi');
});

it('mette il riquadro subito prima del ritorno delle persone', function (): void {
    $widgets = array_map(fn ($widget): string => is_string($widget) ? $widget : $widget->widget, (new Dashboard)->getWidgets());
    $position = array_search(NewRegistrationsWidget::class, $widgets, true);

    expect($position)->not->toBeFalse()
        ->and($widgets[$position + 1] ?? null)->toBe(ReturnRatesWidget::class)
        ->and(array_count_values($widgets)[NewRegistrationsWidget::class])->toBe(1);
});

it('mostra il numero delle iscrizioni da completare nel menu, nel gruppo «Persone»', function (): void {
    expect(UserResource::getNavigationGroup())->toBe(__('users.navigation_group'))
        ->and(UserResource::getNavigationBadge())->toBeNull();

    admUtentiRegistrato('badge@example.test');
    // Un'iscrizione di un mese fa mai confermata non pesa sul numero del menu.
    User::factory()->unverified()->create(['created_at' => now()->subMonth()]);

    expect(UserResource::getNavigationBadge())->toBe('1');
});

it('lascia elenco e riquadro solo alla redazione', function (): void {
    $utente = User::factory()->create();
    $utente->assignRole(UserRole::User->value);
    $this->actingAs($utente);

    expect(NewRegistrationsWidget::canView())->toBeFalse();
    $this->get('/admin/users?tab=pending')->assertForbidden();

    $moderatore = User::factory()->create();
    $moderatore->assignRole(UserRole::Moderator->value);
    $this->actingAs($moderatore);

    // Il moderatore legge l'elenco ma non apre la modifica dal riquadro.
    $registrato = admUtentiRegistrato('per.moderatore@example.test', 'Visibile Al Moderatore');
    expect(NewRegistrationsWidget::canView())->toBeTrue();
    Livewire::test(NewRegistrationsWidget::class)->assertOk()->assertSee('Visibile Al Moderatore')
        ->assertDontSee(UserResource::getUrl('edit', ['record' => $registrato]), escape: false);
});
