<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveManagementSubject;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/*
 * Le due garanzie strutturali dell'API di gestione (piano android-gestione,
 * §3.1): nessuna rotta sotto un soggetto senza la porta che controlla
 * l'accesso, e nessun controller di gestione che parli con Filament (lo
 * scope di tenancy dei pannelli lì non c'è, e le query si restringono con
 * `ManagedContent`).
 */
uses(TestCase::class);

it('mette ResolveManagementSubject su ogni rotta di un locale o di un’organizzazione', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RouteDefinition $route): bool => str_contains($route->uri(), 'management/venues/') || str_contains($route->uri(), 'management/organizers/'));

    $missing = $routes
        ->reject(fn (RouteDefinition $route): bool => in_array(ResolveManagementSubject::class, $route->gatherMiddleware(), true))
        ->map(fn (RouteDefinition $route): string => implode('|', $route->methods()).' '.$route->uri())
        ->values()->all();

    expect($routes)->not->toBeEmpty()
        ->and($missing)->toBe([]);
});

arch('i controller di gestione non importano Filament')
    ->expect('App\Http\Controllers\Api\V1\Management')
    ->not->toUse('Filament');
