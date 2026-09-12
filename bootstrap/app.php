<?php

use App\Http\Middleware\Api\AlwaysJson;
use App\Http\Middleware\AuthenticateWebSession;
use App\Http\Middleware\InstallerSession;
use App\Http\Middleware\PreventSharedResponseCache;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Api\ApiExceptionRenderer;
use App\Support\SecurityLog;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;
use Spatie\MissingPageRedirector\RedirectsMissingPages;

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

        /*
         * L'installer scrive la sessione su file (D42): `SESSION_DRIVER` è
         * `database`, ma il wizard esiste proprio perché quel database non c'è
         * ancora. Sta in testa al gruppo `web` e non fra i middleware delle
         * rotte dell'installer perché `StartSession` appartiene al gruppo, e un
         * middleware di rotta girerebbe dopo — cioè troppo tardi. Fuori dagli
         * indirizzi dell'installer non fa nulla.
         */
        $middleware->prependToGroup('web', InstallerSession::class);
        $middleware->prependToGroup('web', PreventSharedResponseCache::class);

        /*
         * Reimpostare la password deve CHIUDERE le sessioni già aperte.
         *
         * Senza questo middleware non le chiudeva: chi si era fatto rubare la
         * password poteva cambiarla e il ladro restava dentro, con il suo
         * cookie, fino alla scadenza naturale della sessione — cioè la
         * reimpostazione, che è il gesto con cui una persona riprende il
         * controllo del proprio account, non riprendeva il controllo di
         * niente.
         *
         * `AuthenticateWebSession` scrive nella sessione l'impronta della
         * password e la confronta a ogni richiesta: quando l'impronta non
         * corrisponde più, la sessione cade. È la difesa che Laravel fornisce
         * proprio per questo — agganciata però al guard `web` e non a quello
         * predefinito, che è uno stato globale mutabile: vedi la classe.
         *
         * Va nel gruppo `web` perché deve valere per tutte le pagine con una
         * sessione, non per quelle che qualcuno si ricorda di marcare.
         */
        $middleware->appendToGroup('web', AuthenticateWebSession::class);

        /*
         * Gli indirizzi che non esistono più (tabella `redirects`).
         *
         * **Globale e non nel gruppo `web`**, che è la sola posizione in cui
         * funziona per intero: i middleware di gruppo sono attaccati alle
         * rotte, e un indirizzo che non corrisponde a nessuna rotta non entra
         * mai nel gruppo — cioè proprio il caso di una sezione sparita o di un
         * vecchio sito. Da qui si vedono invece tutti e due i casi, perché il
         * 404 sollevato più a fondo torna indietro come risposta lungo questa
         * stessa catena.
         *
         * Costa fino a tre letture indicizzate — la città, la riga esatta, le
         * poche righe jolly — e solo sui 404, nemmeno su tutti:
         * `RedirectDalDatabase` scarta prima i metodi diversi da GET e le
         * sezioni che non hanno indirizzi pubblici da salvare. È poco accanto
         * alla pagina d'errore che quella stessa richiesta sta già disegnando.
         */
        $middleware->append(RedirectsMissingPages::class);

        /*
         * Le intestazioni di sicurezza (§16), da qui e non dal pannello
         * dell'hosting: vedi `SecurityHeaders`.
         *
         * **Globale e in coda**, come il redirector qui sopra e per la stessa
         * ragione più una. La stessa: da qui passano anche le risposte che non
         * corrispondono a nessuna rotta — 404, errori — che nel gruppo `web`
         * non entrerebbero mai. La seconda: in coda significa che sulla via
         * del ritorno è l'ultimo a guardare la risposta, quindi vede ciò che
         * controller e middleware di rotta hanno già deciso e si limita a
         * riempire i vuoti.
         */
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Il tracciamento degli errori di §16. Senza DSN in `.env` il pacchetto
         * non si avvia e questa riga non fa nulla: nessun tentativo di rete,
         * nessuna eccezione, nessun rallentamento — in sviluppo, nei test e
         * nella pipeline non c'è niente da spegnere.
         */
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * Ogni errore dell'API esce nella forma di §13.6 — codice, messaggio,
         * campi — con lo stato HTTP che gli corrisponde. Senza questa riga il
         * client dovrebbe conoscere tre forme diverse: quella della
         * validazione, quella delle eccezioni HTTP e quella del 500.
         */
        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if ($request->is('api/v1/auth/*') || $request->routeIs('account.login.*', 'account.magic-link.*', 'account.password.*')) {
                SecurityLog::blocco();
            }

            return null;
        });

        $exceptions->render(new ApiExceptionRenderer);
    })->create();
