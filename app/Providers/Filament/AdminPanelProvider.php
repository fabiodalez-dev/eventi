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
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Colors\Color;
use Guava\Calendar\CalendarPlugin;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
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
            ->login()
            /*
             * Un amministratore che dimentica la password restava fuori: non
             * c'e' registrazione da rifare e nessuno a cui chiedere, perche'
             * chi potrebbe rimediare e' lui. Il pannello dei locali lo aveva
             * gia'; questo no, e la differenza non era voluta.
             */
            ->passwordReset()
            ->brandName(fn (): string => (string) config('app.name'))
            /*
             * Lo stesso carattere del sito, servito dal nostro dominio: e' gia'
             * in `@fontsource`, quindi non aggiunge una richiesta a Google.
             */
            ->font('Archivo Variable')
            /*
             * Il foglio che porta qui dentro l'identita' del sito — spigoli
             * vivi, neutri caldi, bordi visibili — senza portarne l'intensita':
             * il perche' di ogni scelta sta scritto li'.
             */
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->colors([
                /*
                 * Il lime del sito al posto dell'indaco predefinito. Filament
                 * ne ricava una scala completa: serve per gli stati, dove un
                 * colore solo non basta.
                 */
                'primary' => Color::hex('#ccff00'),
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
             * `loadedOnRequest()` non si usa: il componente lo cerca appena
             * la pagina si apre, e caricarlo su richiesta significherebbe
             * mostrare un riquadro vuoto finche' non arriva. Leaflet resta
             * comunque fuori — lo importa il file, dinamicamente, solo quando
             * una mappa esiste davvero.
             */
            ->assets([
                /* `module()`: Vite compila con `import`/`export`, e servito
                   come script classico il browser si ferma su «Cannot use
                   import statement outside a module» — la mappa non compare e
                   l'errore parla di sintassi, non di mappe. */
                Js::make('map-picker', Vite::asset('resources/js/filament-map.js'))->module(),
                Css::make('map-picker', Vite::asset('resources/css/filament-map.css')),
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
