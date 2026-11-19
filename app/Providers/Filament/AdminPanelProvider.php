<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Support\PanelNavigation;
use App\Http\Middleware\AuthenticatePanel;
use App\Http\Middleware\AuthenticatePanelSession;
use App\Http\Middleware\RevalidateImpersonation;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationBuilder;
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
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Il pannello di redazione, §9 del piano.
 *
 * Chi entra lo decide `User::canAccessPanel()`: amministratore, amministratore
 * di sistema, moderatore. Cosa può fare una volta dentro non lo decide questo
 * file — lo decidono le Policy di `app/Policies`, che Filament interroga da sé
 * su ogni risorsa, riga e azione.
 *
 * Nessuna etichetta è scritta qui: il menu lo costruisce `PanelNavigation`
 * con i nomi delle pagine, i gruppi arrivano da `lang/it/pannelli-impianto.php`.
 * Il nome del prodotto arriva sempre da `config('app.name')` (D10).
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            /*
             * Nessuna pagina di accesso propria: si entra da `/accedi`, il
             * login unico, e da lì ognuno arriva nella propria area o alla
             * pagina da cui era partito (`PostLoginDestination`). Anche il
             * recupero della password è quello del sito; i vecchi indirizzi
             * `/admin/login` e `/admin/password-reset/*` rimandano lì
             * (`routes/account.php`). Chi è dentro ma non è della redazione
             * riceve un 403 che lo dice (`AuthenticatePanel`).
             */
            ->brandName(fn (): string => (string) config('app.name'))
            /*
             * Lo stesso carattere del sito, servito dal nostro dominio: e' gia'
             * in `@fontsource`, quindi non aggiunge una richiesta a Google.
             */
            ->font('Manrope Variable', provider: LocalFontProvider::class)
            /*
             * Il menu della persona è lo stesso nei tre pannelli: chi è dentro,
             * «Area personale», «Tema chiaro o scuro», «Esci». Vedi
             * `PanelNavigation::userMenu()`.
             */
            ->userMenuItems(PanelNavigation::userMenu())
            ->renderHook(PanelsRenderHook::USER_MENU_PROFILE_BEFORE, fn () => view('filament.partials.user-menu-identity'))
            /*
             * Il tema è quello scelto sul sito (`/aspetto`): lo script in testa
             * alla pagina lo scrive dove Filament lo legge, prima che la pagina
             * si disegni. Il commutatore di Filament è spento: il tema si
             * sceglie in un posto solo. Vedi `filament.partials.theme-bootstrap`.
             */
            ->darkMode(true)
            ->themeSwitcher(false)
            ->renderHook(PanelsRenderHook::HEAD_START, fn () => view('filament.partials.theme-bootstrap'))
            ->renderHook(PanelsRenderHook::TOPBAR_END, fn () => view('filament.partials.topbar-actions'))
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
            /*
             * Ordine, gruppi e icone del menu stanno in un file solo,
             * `PanelNavigation`, uguale per i tre pannelli; il nome di ogni
             * voce lo dà la pagina.
             */
            ->navigation(fn (NavigationBuilder $builder): NavigationBuilder => PanelNavigation::admin($builder))
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
}
