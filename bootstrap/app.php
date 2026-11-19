<?php

use App\Http\Middleware\Api\AlwaysJson;
use App\Http\Middleware\AuthenticateWebSession;
use App\Http\Middleware\InstallerSession;
use App\Http\Middleware\PreventSharedResponseCache;
use App\Http\Middleware\RevalidateImpersonation;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Api\ApiExceptionRenderer;
use App\Support\Auth\PostLoginDestination;
use App\Support\SecurityLog;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Sentry\Laravel\Integration;
use Spatie\MissingPageRedirector\RedirectsMissingPages;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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

        // Un'impersonificazione cade quando chi la fa perde i poteri: vedi la classe.
        $middleware->appendToGroup('web', RevalidateImpersonation::class);

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

        /*
         * Solo l'host di `APP_URL` (e i suoi sottodomini) e' un host valido:
         * una richiesta con un'intestazione `Host` diversa viene respinta
         * prima di generare qualunque collegamento. Laravel applica questo
         * controllo soltanto fuori dall'ambiente locale e dai test.
         */
        $middleware->trustHosts(at: fn (): array => array_filter([parse_url((string) config('app.url'), PHP_URL_HOST)]));

        /*
         * Il tema scelto da ospite lo scrive lo script del browser, in chiaro:
         * cifrarlo lo renderebbe illeggibile a chi lo ha scritto, e per il
         * server — che lo usa per la chiave della cache delle pagine e per
         * copiarlo nel profilo al primo accesso — sarebbe sempre vuoto. È una
         * preferenza d'aspetto, non un segreto; il valore si accetta solo se
         * è uno dei due temi.
         */
        $middleware->encryptCookies(except: ['incitta_appearance']);

        /*
         * Chi apre `/accedi` (o un'altra pagina riservata agli ospiti) quando
         * è già dentro va nella propria area — o alla pagina di ritorno
         * scritta nell'indirizzo, se la può aprire: è anche la strada di «Vai
         * alla tua area» della pagina 403.
         */
        $middleware->redirectUsersTo(fn (Request $request): string => PostLoginDestination::forRequest($request));
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

        /*
         * Un collegamento firmato dell'accesso o della conferma dell'email
         * scaduto o già usato non è un «non puoi aprire questa pagina»: chi
         * si è appena registrato deve sapere che basta chiederne uno nuovo.
         */
        $exceptions->render(function (InvalidSignatureException $exception, Request $request) {
            if ($request->expectsJson() || ! $request->routeIs('account.magic-link.login', 'account.magic-link.confirm', 'verification.verify')) {
                return null;
            }

            return response()->view('errors.link-expired', ['exception' => $exception], 403);
        });

        /*
         * La sessione scaduta (419) e il limite dei tentativi (429) sui moduli
         * di accesso, registrazione, collegamento, recupero e nuova password
         * non sono una pagina d'errore: si torna al modulo, con l'indirizzo
         * già scritto e il motivo sopra (`$errors->get('form')`). Il 429
         * conserva `Retry-After`, e il messaggio dice quanto aspettare.
         * Le richieste JSON (l'app) restano come sono.
         */
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            $status = $exception->getStatusCode();
            $forms = [
                'account.login.store' => fn (): string => route('login'),
                'account.register.store' => fn (): string => route('account.register'),
                'account.magic-link.store' => fn (): string => route('account.magic-link'),
                'account.password.email' => fn (): string => route('account.password.request'),
                'account.password.update' => fn (): string => route('account.password.reset', array_filter([
                    'token' => is_string($request->input('token')) ? $request->input('token') : null,
                    'email' => is_string($request->input('email')) ? $request->input('email') : null,
                ])),
            ];
            $route = $request->route()?->getName();

            if (! in_array($status, [419, 429], true)
                || ! is_string($route)
                || ! isset($forms[$route])
                || $request->expectsJson()
                || ! $request->hasSession()) {
                return null;
            }

            $headers = $exception->getHeaders();
            $wait = is_numeric($headers['Retry-After'] ?? null) ? max(1, (int) $headers['Retry-After']) : null;
            $message = match (true) {
                $status === 419 => __('auth.form.expired'),
                $wait !== null && $wait > 90 => trans_choice('auth.form.throttled_minutes', (int) ceil($wait / 60), ['minutes' => (int) ceil($wait / 60)]),
                default => trans_choice('auth.form.throttled', $wait ?? 60, ['seconds' => $wait ?? 60]),
            };

            $response = redirect()->to($forms[$route]())
                ->withInput($request->only(['email', 'first_name', 'last_name', 'remember', 'marketing_opt_in']))
                ->withErrors(['form' => $message]);

            if ($status === 429 && isset($headers['Retry-After'])) {
                $response->headers->set('Retry-After', (string) $headers['Retry-After']);
            }

            return $response;
        });

        /*
         * Un conflitto (409) o un dato non accettabile (422) dopo l'invio di
         * un modulo del sito non è una pagina d'errore: è il modulo che non è
         * passato. Si torna al modulo, con i dati già scritti e il messaggio
         * nel riepilogo degli errori (`$errors->get('form')`).
         *
         * Solo per i moduli HTML: le letture (GET), le richieste JSON, quelle
         * di Livewire (i pannelli) e l'API hanno già la loro risposta. Il
         * ritorno segue il Referer solo verso questo stesso sito: un indirizzo
         * esterno diventa la prima pagina.
         */
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (! in_array($exception->getStatusCode(), [409, 422], true)
                || $request->isMethodSafe()
                || $request->expectsJson()
                || $request->headers->has('X-Livewire')
                || $request->is('api/*')
                || ! $request->hasSession()) {
                return null;
            }

            $previous = (string) $request->headers->get('referer', '');
            $host = $previous !== '' ? parse_url($previous, PHP_URL_HOST) : null;
            $target = is_string($host) && strcasecmp($host, $request->getHost()) === 0 ? $previous : url('/');

            $message = trim($exception->getMessage()) !== ''
                ? $exception->getMessage()
                : __($exception->getStatusCode() === 409 ? 'errors.form.conflict' : 'errors.form.unprocessable');

            return redirect()->to($target)
                ->withInput($request->except(['password', 'password_confirmation', 'current_password', '_token', '_method']))
                ->withErrors(['form' => $message]);
        });
    })->create();
