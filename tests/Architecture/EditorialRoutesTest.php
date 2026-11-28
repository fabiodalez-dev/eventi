<?php

declare(strict_types=1);

use App\Http\Middleware\RequireEditorialUnlock;
use App\Http\Middleware\RequireRecentPassword;
use App\Http\Middleware\ResolveEditorialDesk;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/*
 * Le garanzie strutturali dell'API della redazione (piano android-redazione,
 * §3.1 e §3.4), valide per ogni pacchetto che aggiunge rotte nel suo file
 * `routes/editorial/<pacchetto>.php`:
 *
 * - nessuna rotta senza la porta (`ResolveEditorialDesk`: solo la redazione,
 *   interruttore d'emergenza, città) e senza `auth:sanctum`;
 * - nessuna rotta senza lo sblocco delle 12 ore, tranne il banco, gli
 *   elenchi e lo sblocco stesso;
 * - ogni rotta ha un nome sotto `api.v1.editorial.`;
 * - le azioni pericolose della decisione 4 chiedono la password recente;
 * - nessun controller della redazione parla con Filament.
 */
uses(TestCase::class);

/** Le rotte che servono a sapere se la redazione è sbloccata e a sbloccarla. */
const EDITORIAL_OPEN_ROUTES = ['api.v1.editorial.desk', 'api.v1.editorial.options', 'api.v1.editorial.unlock'];

/*
 * Le azioni pericolose (decisione 4), riconosciute dal nome della rotta.
 * Per convenzione (contratto delle fondamenta) l'ultimo pezzo del nome è:
 * `destroy` per l'eliminazione (anche soft) di un'entità, `merge` per
 * un'unione, `roles` per il cambio dei ruoli, `suspend` per una
 * sospensione. Le altre stanno nell'elenco dei nomi qui sotto; un pacchetto
 * che ne aggiunge una aggiunge la sua riga.
 */
const EDITORIAL_DANGEROUS_SUFFIXES = ['destroy', 'merge', 'roles', 'suspend'];
const EDITORIAL_DANGEROUS_NAMES = [
    'api.v1.editorial.events.cancel',
    'api.v1.editorial.catalog.cities.update',
    // Una città nuova e attiva compare subito nel sito: come la modifica.
    'api.v1.editorial.catalog.cities.store',
    'api.v1.editorial.carpool.cases.hold',
    // R7: la lettura delle evidenze di un caso (nel pannello chiede la password).
    'api.v1.editorial.carpool.cases.evidence',
    // R14: pubblicare, programmare e ripubblicare un post sui canali della città, visibile a tutti.
    'api.v1.editorial.social.batches.publish',
    'api.v1.editorial.social.batches.schedule',
    'api.v1.editorial.social.publications.retry',
    // Revisione dell'ondata 3: scritture che arrivano a tutti o che pubblicano da sole.
    'api.v1.editorial.social.settings.update',
    'api.v1.editorial.social.meta.disconnect',
    'api.v1.editorial.settings.newsletter.update',
    'api.v1.editorial.settings.texts.update',
    'api.v1.editorial.settings.texts.reset',
    'api.v1.editorial.import-sources.run',
    'api.v1.editorial.import-sources.store',
    'api.v1.editorial.import-sources.update',
    // Chi scrive il codice di Search Console diventa proprietario verificato del sito.
    'api.v1.editorial.seo.verification',
    // Revisione di sicurezza dell'ondata 1, punto 3: un account nuovo può nascere con un ruolo della redazione.
    'api.v1.editorial.users.store',
];

/** @return Collection<int, RouteDefinition> */
function editorialRoutes(): Collection
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RouteDefinition $route): bool => str_starts_with($route->uri(), 'api/v1/editorial/') || $route->uri() === 'api/v1/editorial')
        ->values();
}

function describeRoute(RouteDefinition $route): string
{
    return implode('|', $route->methods()).' '.$route->uri();
}

it('mette auth:sanctum e ResolveEditorialDesk su ogni rotta della redazione', function (): void {
    $routes = editorialRoutes();

    $missing = $routes
        ->reject(fn (RouteDefinition $route): bool => in_array(ResolveEditorialDesk::class, $route->gatherMiddleware(), true)
            && in_array('auth:sanctum', $route->gatherMiddleware(), true))
        ->map(describeRoute(...))->values()->all();

    expect($routes)->not->toBeEmpty()
        ->and($missing)->toBe([]);
});

it('chiede lo sblocco su ogni rotta tranne banco, elenchi e sblocco', function (): void {
    $locked = editorialRoutes()->reject(fn (RouteDefinition $route): bool => in_array($route->getName(), EDITORIAL_OPEN_ROUTES, true));

    $missing = $locked
        ->reject(fn (RouteDefinition $route): bool => in_array(RequireEditorialUnlock::class, $route->gatherMiddleware(), true))
        ->map(describeRoute(...))->values()->all();

    $open = editorialRoutes()
        ->filter(fn (RouteDefinition $route): bool => in_array($route->getName(), EDITORIAL_OPEN_ROUTES, true))
        ->map(fn (RouteDefinition $route): ?string => $route->getName())->sort()->values()->all();

    expect($missing)->toBe([])
        ->and($open)->toBe(['api.v1.editorial.desk', 'api.v1.editorial.options', 'api.v1.editorial.unlock']);
});

it('dà a ogni rotta della redazione un nome sotto api.v1.editorial.', function (): void {
    $unnamed = editorialRoutes()
        ->reject(fn (RouteDefinition $route): bool => str_starts_with((string) $route->getName(), 'api.v1.editorial.'))
        ->map(describeRoute(...))->values()->all();

    expect($unnamed)->toBe([]);
});

it('chiede la password recente alle azioni pericolose, e solo dopo lo sblocco', function (): void {
    $dangerous = editorialRoutes()->filter(function (RouteDefinition $route): bool {
        $name = (string) $route->getName();

        return in_array($name, EDITORIAL_DANGEROUS_NAMES, true)
            || in_array(str($name)->afterLast('.')->toString(), EDITORIAL_DANGEROUS_SUFFIXES, true);
    });

    $missing = $dangerous
        ->reject(fn (RouteDefinition $route): bool => in_array(RequireRecentPassword::class, $route->gatherMiddleware(), true))
        ->map(describeRoute(...))->values()->all();

    // La password recente non sostituisce lo sblocco: una rotta che la chiede ha anche le 12 ore.
    $withoutUnlock = editorialRoutes()
        ->filter(fn (RouteDefinition $route): bool => in_array(RequireRecentPassword::class, $route->gatherMiddleware(), true))
        ->reject(fn (RouteDefinition $route): bool => in_array(RequireEditorialUnlock::class, $route->gatherMiddleware(), true))
        ->map(describeRoute(...))->values()->all();

    expect($missing)->toBe([])
        ->and($withoutUnlock)->toBe([]);
});

it('mette un limite di frequenza con nome su ogni rotta della redazione', function (): void {
    $missing = editorialRoutes()
        ->reject(fn (RouteDefinition $route): bool => collect($route->gatherMiddleware())
            ->contains(fn (mixed $middleware): bool => is_string($middleware) && preg_match('/^throttle:\d+,\d+,[a-z0-9-]+$/', $middleware) === 1))
        ->map(describeRoute(...))->values()->all();

    expect($missing)->toBe([]);
});

arch('i controller della redazione non importano Filament')
    ->expect('App\Http\Controllers\Api\V1\Editorial')
    ->not->toUse(['Filament', 'App\Filament']);
