<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Auth\Login;
use App\Filament\Auth\RequestPasswordReset;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Guava\Calendar\CalendarPlugin;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Vite;
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
            ->login(Login::class)
            /*
             * Un amministratore che dimentica la password restava fuori: non
             * c'e' registrazione da rifare e nessuno a cui chiedere, perche'
             * chi potrebbe rimediare e' lui. Il pannello dei locali lo aveva
             * gia'; questo no, e la differenza non era voluta.
             */
            ->passwordReset(RequestPasswordReset::class)
            ->brandName(fn (): string => (string) config('app.name'))
            /*
             * Lo stesso carattere del sito, servito dal nostro dominio: e' gia'
             * in `@fontsource`, quindi non aggiunge una richiesta a Google.
             */
            ->font('Manrope Variable', provider: LocalFontProvider::class)
            ->navigationItems([NavigationItem::make('Il mio profilo')
                ->icon('heroicon-o-user-circle')->url(fn (): string => route('account.profile')),
            ])
            ->darkMode(false)
            /*
             * Il foglio che porta qui dentro l'identita' del sito — titoli Bricolage,
             * superfici chiare e bordi sottili — senza portarne l'intensita':
             * il perche' di ogni scelta sta scritto li'.
             */
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->colors([
                /*
                 * L'arancione del tema chiaro del sito. Filament
                 * ne ricava una scala completa: serve per gli stati, dove un
                 * colore solo non basta.
                 */
                'primary' => Color::hex('#b54d23'),
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
            /* Il calendario della redazione (`guava/calendar`): il plugin
               porta il foglio di stile e lo script del widget, che senza di
               esso resterebbe un riquadro vuoto. */
            ->plugin(CalendarPlugin::make())

            /*
             * Il segnaposto trascinabile dei moduli.
             *
             * **Non `->assets()` con `Vite::asset()`.** Quel metodo risolve il
             * percorso *quando il pannello si registra*, e i provider vengono
             * istanziati anche da `package:discover` — che gira dentro
             * `composer install`, prima che gli asset esistano. Il risultato
             * era `ViteManifestNotFoundException` a ogni installazione pulita:
             * verde in locale, dove il manifest c'era gia', e rosso sulla CI
             * su tutti e tre i job insieme, compresa l'analisi statica che con
             * le mappe non c'entra niente.
             *
             * Con il render hook la risoluzione avviene mentre si disegna la
             * pagina, quando il manifest c'e' per definizione.
             *
             * `@vite` marca i moduli da se': Vite compila con
             * `import`/`export`, e servito come script classico il browser si
             * ferma su «Cannot use import statement outside a module» — un
             * errore che parla di sintassi mentre il guasto e' una mappa che
             * non compare.
             */
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => Blade::render("@vite(['resources/js/filament-map.js', 'resources/css/filament-map.css'])"),
            )

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
