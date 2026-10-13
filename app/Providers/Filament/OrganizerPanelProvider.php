<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Filament\Auth\RequestPasswordReset;
use App\Filament\Organizer\Pages\Dashboard;
use App\Filament\Organizer\Pages\EventAnalyticsDetail;
use App\Filament\Organizer\Pages\EventShareAnalytics;
use App\Filament\Organizer\Pages\Profile;
use App\Filament\Organizer\Pages\Statistics;
use App\Models\Organizer;
use Filament\Actions\Action;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class OrganizerPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('organizer')->path('organizza')->login(Login::class)->passwordReset(RequestPasswordReset::class)
            ->userMenuItems([
                Action::make('profile')
                    ->label(fn (): string => __('manage.navigation.profile'))
                    ->icon(Heroicon::OutlinedUserCircle)
                    ->url(fn (): string => route('account.profile')),
            ])
            ->navigationGroups([
                NavigationGroup::make(fn (): string => __('manage.navigation.analytics'))->collapsed(),
                NavigationGroup::make(fn (): string => __('manage.navigation.community')),
            ])
            ->renderHook(PanelsRenderHook::TOPBAR_END, fn () => view('filament.partials.topbar-actions'))
            ->darkMode(false)
            ->font('Manrope Variable', provider: LocalFontProvider::class)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->brandName(fn (): string => (string) config('app.name'))->colors(['primary' => Color::hex('#b54d23')])
            ->tenant(Organizer::class, ownershipRelationship: 'organizer')
            ->pages([Dashboard::class, Profile::class, Statistics::class, EventShareAnalytics::class, EventAnalyticsDetail::class])
            ->discoverResources(in: app_path('Filament/Organizer/Resources'), for: 'App\\Filament\\Organizer\\Resources')
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
            ])->authMiddleware([Authenticate::class]);
    }
}
