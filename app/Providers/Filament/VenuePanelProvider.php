<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Venue\Pages\Dashboard;
use App\Models\User;
use App\Models\Venue;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Vite;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Il pannello dei locali, §10 del piano.
 *
 * **Chi lo usa non è seduto a una scrivania.** È in piedi dietro al bancone,
 * con il telefono in una mano: ogni scelta qui dentro è subordinata a questo.
 * Nessun gruppo di navigazione (le voci sono cinque, un gruppo sarebbe un
 * livello in più da aprire), nessuna colonna che non si legga su 390 pixel,
 * nessuna azione che non si possa premere con il pollice.
 *
 * **Tenancy su `Venue`.** Il locale sta nell'indirizzo
 * (`/gestione/{slug}/eventi`) e Filament restringe da sé ogni query a quello,
 * ma la restrizione non è il muro: il muro sono
 * `User::canAccessTenant()`, che risponde 404 a un locale che non è tuo prima
 * ancora di aprire la pagina, e le Policy di `app/Policies`, che verificano il
 * `venue_id` riga per riga. Tre barriere per la stessa domanda, perché §18
 * scenario F chiede che regga anche a un ID scritto a mano nell'indirizzo.
 *
 * **Nessuna registrazione libera.** Chi entra qui è stato invitato — dalla
 * redazione come referente, o da un referente come collaboratore
 * (`InviteVenueMemberAction`). Il recupero della password è invece attivo:
 * è il collegamento che l'invito stesso porta con sé.
 */
class VenuePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('venue')
            ->path('gestione')
            ->login()
            ->passwordReset()
            ->darkMode(false)
            ->font('Manrope Variable', provider: LocalFontProvider::class)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->brandName(fn (): string => (string) config('app.name'))
            ->colors([
                'primary' => Color::hex('#b54d23'),
            ])
            ->tenant(Venue::class, ownershipRelationship: 'venue')
            // Lo switcher ha senso solo per chi gestisce più di un locale:
            // a tutti gli altri sarebbe un menu con una voce sola.
            ->tenantMenu(fn (): bool => self::managesSeveralVenues())
            ->discoverResources(in: app_path('Filament/Venue/Resources'), for: 'App\Filament\Venue\Resources')
            ->discoverPages(in: app_path('Filament/Venue/Pages'), for: 'App\Filament\Venue\Pages')
            ->discoverWidgets(in: app_path('Filament/Venue/Widgets'), for: 'App\Filament\Venue\Widgets')
            ->pages([
                Dashboard::class,
            ])
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

    private static function managesSeveralVenues(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->venues()->count() > 1;
    }
}
