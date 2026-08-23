<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Admin\Pages\Dashboard;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Il pannello di redazione, §9 del piano.
 *
 * Chi entra lo decide `User::canAccessPanel()`: amministratore, amministratore
 * di sistema, moderatore. Cosa può fare una volta dentro non lo decide questo
 * file — lo decidono le Policy di `app/Policies`, che Filament interroga da sé
 * su ogni risorsa, riga e azione.
 *
 * Nessuna etichetta è scritta qui: i gruppi di navigazione, come tutto il
 * resto, arrivano da `lang/it/admin.php`. Il nome del prodotto arriva sempre
 * da `config('app.name')` (D10).
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName(fn (): string => (string) config('app.name'))
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->navigationGroups([
                NavigationGroup::make(fn (): string => __('admin.navigation.content')),
                NavigationGroup::make(fn (): string => __('admin.navigation.places')),
                NavigationGroup::make(fn (): string => __('admin.navigation.moderation')),
                NavigationGroup::make(fn (): string => __('admin.navigation.people')),
                NavigationGroup::make(fn (): string => __('admin.navigation.system')),
            ])
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')
            ->pages([
                Dashboard::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
