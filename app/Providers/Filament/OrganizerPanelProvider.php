<?php

namespace App\Providers\Filament;

use App\Filament\Organizer\Pages\Profile;
use App\Models\Organizer;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
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
        return $panel->id('organizer')->path('organizza')->login()->passwordReset()
            ->navigationItems([NavigationItem::make('Il mio profilo')
                ->icon('heroicon-o-user-circle')->url(fn (): string => route('account.profile')),
            ])
            ->darkMode(false)
            ->font('Manrope Variable', provider: LocalFontProvider::class)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->brandName('inCittà · Organizzatori')->colors(['primary' => Color::hex('#b54d23')])
            ->tenant(Organizer::class, ownershipRelationship: 'organizer')
            ->pages([Profile::class])
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
