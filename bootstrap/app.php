<?php

use App\Http\Middleware\Api\AlwaysJson;
use App\Support\Api\ApiExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Il limite di frequenza dell'API (§13.4): 60 richieste al minuto per
         * chi non è autenticato, 120 per chi lo è. Il gruppo `api` di Laravel
         * lo applica con il limitatore omonimo, definito in AppServiceProvider,
         * ed è ciò che emette le intestazioni `X-RateLimit-*`.
         */
        $middleware->throttleApi('api');

        /*
         * Ogni richiesta all'API è una richiesta JSON, l'abbia dichiarato o
         * no: è ciò che fa uscire un 401 invece di un rimando alla pagina di
         * accesso del sito, che non esiste.
         */
        $middleware->api(prepend: [AlwaysJson::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * Ogni errore dell'API esce nella forma di §13.6 — codice, messaggio,
         * campi — con lo stato HTTP che gli corrisponde. Senza questa riga il
         * client dovrebbe conoscere tre forme diverse: quella della
         * validazione, quella delle eccezioni HTTP e quella del 500.
         */
        $exceptions->render(new ApiExceptionRenderer);
    })->create();
