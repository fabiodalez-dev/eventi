<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Organizer\Pages\Dashboard;
use App\Filament\Organizer\Pages\EventAnalyticsDetail;
use App\Filament\Organizer\Pages\EventShareAnalytics;
use App\Filament\Organizer\Pages\Profile;
use App\Filament\Organizer\Pages\SponsorshipAnalytics;
use App\Filament\Organizer\Pages\Sponsorships;
use App\Filament\Organizer\Pages\Statistics;
use App\Filament\Support\PanelNavigation;
use App\Http\Middleware\AuthenticatePanel;
use App\Http\Middleware\AuthenticatePanelSession;
use App\Http\Middleware\RevalidateImpersonation;
use App\Models\Organizer;
use App\Models\User;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationBuilder;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Il pannello di chi organizza eventi in più locali (`/organizza`).
 *
 * Ha lo stesso impianto degli altri due: menu laterale con gli stessi gruppi
 * (`PanelNavigation`), lo stesso menu della persona, la stessa barra in alto,
 * il tema scelto sul sito. Si entra dal login unico (`/accedi`); chi entra lo
 * decide `User::canAccessPanel()`, cosa vede lo decidono le Policy.
 */
class OrganizerPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('organizer')
            ->path('organizza')
            ->navigation(fn (NavigationBuilder $builder): NavigationBuilder => PanelNavigation::organizer($builder))
            ->userMenuItems(PanelNavigation::userMenu())
            ->renderHook(PanelsRenderHook::USER_MENU_PROFILE_BEFORE, fn () => view('filament.partials.user-menu-identity'))
            ->renderHook(PanelsRenderHook::TOPBAR_END, fn () => view('filament.partials.topbar-actions'))
            // Il tema è quello scelto sul sito: vedi `AdminPanelProvider`.
            ->darkMode(true)
            ->themeSwitcher(false)
            ->renderHook(PanelsRenderHook::HEAD_START, fn () => view('filament.partials.theme-bootstrap'))
            ->font('Manrope Variable', provider: LocalFontProvider::class)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->brandName(fn (): string => (string) config('app.name'))
            ->colors(['primary' => Color::hex('#b54d23')])
            ->tenant(Organizer::class, ownershipRelationship: 'organizer')
            /* Il selettore dell'organizzazione serve solo a chi ne gestisce
               più di una: agli altri ripeteva il nome già scritto in alto. */
            ->tenantMenu(fn (): bool => self::managesSeveralOrganizers())
            ->pages([Dashboard::class, Profile::class, Sponsorships::class, SponsorshipAnalytics::class, Statistics::class, EventShareAnalytics::class, EventAnalyticsDetail::class])
            ->discoverResources(in: app_path('Filament/Organizer/Resources'), for: 'App\\Filament\\Organizer\\Resources')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticatePanelSession::class,
                RevalidateImpersonation::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                AuthenticatePanel::class,
            ]);
    }

    private static function managesSeveralOrganizers(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->managedOrganizers()->count() > 1;
    }
}
