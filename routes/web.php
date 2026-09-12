<?php

declare(strict_types=1);

use App\Http\Controllers\Web\ConsentController;
use App\Http\Controllers\Web\DeployController;
use App\Http\Controllers\Web\EventController;
use App\Http\Controllers\Web\ImpersonationController;
use App\Http\Controllers\Web\MetaOAuthController;
use App\Http\Controllers\Web\PageController;
use App\Http\Controllers\Web\ReleaseStatusController;
use App\Http\Controllers\Web\SeoController;
use App\Http\Controllers\Web\SocialDownloadController;
use App\Http\Controllers\Web\SponsorshipMetricController;
use App\Http\Controllers\Web\WebManifestController;
use App\Http\Controllers\Web\WidgetController;
use App\Http\Middleware\PersonalizeDiscovery;
use App\Http\Middleware\RequiresOpsToken;
use App\Http\Middleware\ResolveCity;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Spatie\Health\Http\Controllers\HealthCheckJsonResultsController;
use Spatie\Health\Http\Controllers\SimpleHealthCheckController;

/*
 * Il wizard di installazione (D42). Sta **in testa** a tutto: il gruppo
 * `/{city}` più sotto accetta qualunque segmento minuscolo, `installazione`
 * compreso, e registrato dopo verrebbe risolto come una città inesistente.
 */
Route::group([], base_path('routes/installer.php'));

Route::get('/app/auth/magic', function () {
    return response()->view('account.mobile-magic-link')
        ->header('Cache-Control', 'no-store, private')
        ->header('Referrer-Policy', 'no-referrer')
        ->header('X-Robots-Tag', 'noindex, nofollow');
})->name('app.magic-link');

Route::get('/release-status', ReleaseStatusController::class)->name('ops.release');

/*
 * Le rotte del sito pubblico stanno in routes/public.php e sono registrate due
 * volte: senza prefisso per la città predefinita e sotto `/{city}` per tutte le
 * altre (§11.1). Il secondo gruppo arriva per ultimo, così `/eventi` resta la
 * rotta della città predefinita e non viene letto come "città eventi".
 */
Route::middleware(PersonalizeDiscovery::class)->group(base_path('routes/public.php'));

/*
 * Account, salvataggi e feed (§15). Stanno fuori dai gruppi del sito pubblico
 * perché non appartengono a una città, e prima del gruppo con il prefisso
 * `/{city}` perché `/accedi` è un indirizzo, non una città che si chiama
 * "accedi".
 */
Route::group([], base_path('routes/account.php'));
Route::get('/anteprima-evento/{event}', [EventController::class, 'preview'])->middleware('auth')->name('events.preview');
Route::group([], base_path('routes/ticketing.php'));
Route::get('/social/grafiche/{batch}/{index}.jpg', [SocialDownloadController::class, 'image'])->middleware('signed')->whereNumber('index')->name('social.image');
Route::get('/social/download/{batch}', [SocialDownloadController::class, 'zip'])->middleware('auth')->name('social.zip');
Route::get('/social/anteprima/{occurrence}', [SocialDownloadController::class, 'preview'])->middleware(['auth', 'throttle:120,1'])->name('social.preview');

/*
 * Lo stato del sistema (§16: monitoring su endpoint **protetto** per l'uptime
 * monitor). Fuori dai gruppi del sito pubblico: non appartiene a una città, e
 * non deve passare da `ResolveCity` — un controllo di stato che dipende dallo
 * stato del database sarebbe cieco proprio quando serve.
 *
 * `/stato` risponde JSON, con 503 quando un controllo è rosso: è ciò che un
 * monitor di uptime capisce senza leggere il corpo. `/stato/completo` porta
 * l'esito di ciascun controllo, per chi sta cercando quale.
 *
 * Sessione, cookie e protezione CSRF sono tolti di proposito: chi interroga
 * questo indirizzo è un programma con una chiave, non un browser con una
 * sessione, e ogni chiamata scriverebbe altrimenti un file di sessione.
 */
Route::middleware(RequiresOpsToken::class)
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
    ])
    ->group(function (): void {
        Route::get('/stato', SimpleHealthCheckController::class)->name('ops.health');

        Route::get('/stato/completo', HealthCheckJsonResultsController::class)->name('ops.health.details');

        /*
         * Il segnale di rilascio (vedi `DeployController`).
         *
         * Sta con le rotte operative perche' condivide il segreto di `/stato`
         * e la stessa regola: senza segreto configurato non esiste. Il limite
         * di frequenza e' stretto — un rilascio al minuto e' gia' piu' di
         * quanto abbia senso — e serve a contenere il costo di chi provasse a
         * indovinare il segreto: ogni tentativo sbagliato riceve comunque 404,
         * ma senza tetto potrebbe provarne molti.
         */
        Route::post('/rilascio', DeployController::class)
            ->middleware('throttle:6,1')
            ->name('ops.deploy');
    });

Route::prefix('{city}')
    ->where(['city' => '[a-z][a-z0-9-]*'])
    ->middleware([ResolveCity::class, PersonalizeDiscovery::class])
    ->name('city.')
    ->group(base_path('routes/public.php'));

/*
 * Mappa del sito e robots (§12.2). Stanno fuori dai gruppi del sito pubblico
 * perché un motore di ricerca cerca `/sitemap.xml` e `/robots.txt` alla radice
 * del dominio e in nessun altro posto: `/padova/robots.txt` non lo leggerebbe
 * nessuno.
 *
 * `robots.txt` è una rotta e non il file statico che stava in `public/`:
 * la riga `Sitemap:` vuole un indirizzo assoluto, e un file scritto a mano lo
 * congelerebbe al dominio di quando è stato scritto.
 */
Route::get('/sitemap.xml', [SeoController::class, 'index'])->name('sitemap.index');

Route::get('/sitemap-{section}-{page}.xml', [SeoController::class, 'section'])
    ->where('section', '[a-z]+')
    ->whereNumber('page')
    ->name('sitemap.section');

Route::get('/robots.txt', [SeoController::class, 'robots'])->name('robots');

/*
 * Il manifesto dell'applicazione web (§15.6). Sta con la mappa del sito e il
 * `robots.txt`, e per la stessa ragione: appartiene al dominio, non a una
 * città, e vuole indirizzi assoluti che dipendono da dove gira il sito.
 */
Route::get('/site.webmanifest', WebManifestController::class)->name('webmanifest');

/*
 * Il widget incorporabile (§11.10) sta fuori dai gruppi del sito pubblico:
 * lo slug del locale è unico in tutto il sistema (D12) e il riquadro parla
 * della città di quel locale, non di quella che sta guardando chi incorpora.
 * Un solo indirizzo, così il codice da incollare resta valido per sempre.
 *
 * Sessione e cookie sono tolti di proposito: il riquadro non ha niente da
 * ricordare, e chi lo incorpora non deve ritrovarsi cookie di terze parti sulla
 * propria pagina per aver mostrato le date del proprio locale.
 */
Route::get('/widget/{venue}', WidgetController::class)
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
    ])
    ->name('widget.show');

/*
 * Impersonificazione (§9.2). Autenticazione e permesso li verifica il
 * controller, che rimanda all'accesso del pannello di redazione: il sito
 * pubblico non ha ancora una rotta `login`, e il middleware `auth` la darebbe
 * per scontata.
 *
 * L'uscita è dichiarata prima dell'ingresso, altrimenti "interrompi" finirebbe
 * nel segnaposto `{user}`. Entrambe stanno fuori dai gruppi del sito pubblico:
 * non appartengono a una città.
 */
Route::get('/impersona/interrompi', [ImpersonationController::class, 'stop'])
    ->name('impersonate.stop');

Route::get('/impersona/{user}', [ImpersonationController::class, 'start'])
    ->whereNumber('user')
    ->name('impersonate.start');

/*
 * Le pagine redazionali di §11.1 e §16: informativa privacy, cookie policy,
 * termini, chi siamo, contatti.
 *
 * Stanno fuori dai due gruppi del sito pubblico per la stessa ragione della
 * mappa del sito: appartengono al dominio, non a una città. Un'informativa
 * privacy per provincia non esiste, e `/padova/pagine/privacy` sarebbe lo
 * stesso testo a un secondo indirizzo — cioè un doppione offerto all'indice.
 */
Route::get('/pagine/{slug}', [PageController::class, 'show'])
    ->where('slug', '[a-z0-9][a-z0-9-]*')
    ->name('pages.show');

/*
 * Il consenso (§16). Fuori dalle città come le pagine legali: la scelta vale
 * per il sito intero.
 *
 * Il limite di frequenza c'è perché questa rotta scrive una riga di registro a
 * ogni chiamata, e una rotta che scrive senza autenticazione è una rotta che
 * qualcuno prima o poi prova a riempire.
 */
Route::post('/consenso', [ConsentController::class, 'store'])
    ->middleware('throttle:consent')
    ->name('consent.store');

Route::delete('/consenso', [ConsentController::class, 'destroy'])
    ->middleware('throttle:consent')
    ->name('consent.destroy');

/*
 * Le misure di una campagna sponsorizzata (§sponsorizzazioni).
 *
 * Sta fra le rotte web e non sotto `/api/v1` di proposito: l'API pubblica e'
 * di sola lettura per decisione del committente, e un test lo verifica
 * enumerando le rotte registrate. Questa scrive — un contatore — e quindi il
 * suo posto e' qui, dove c'e' gia' la sessione e la protezione contro le
 * richieste da altri siti.
 */
Route::post('/sponsorizzazioni/{sponsorship}/{metric}', SponsorshipMetricController::class)
    ->whereIn('metric', ['impressions', 'clicks'])
    /*
     * Un limitatore suo, e non quello del consenso: questa rotta scatta a ogni
     * card sponsorizzata che entra nello schermo, mentre il consenso si da'
     * una volta. Sono due tetti diversi perche' proteggono da due cose diverse
     * — questo dagli abusi grossolani, quello dentro il controller
     * dall'ingrossare UNA campagna, che e' il gesto che finisce in fattura.
     */
    ->middleware('throttle:sponsorship-metrics')
    ->name('sponsorships.metric');

Route::middleware(['auth', 'throttle:20,1'])->prefix('social/meta')->name('social.meta.')->group(function (): void {
    Route::get('/collega', [MetaOAuthController::class, 'connect'])->name('connect');
    Route::get('/callback', [MetaOAuthController::class, 'callback'])->name('callback');
    Route::get('/pagine', [MetaOAuthController::class, 'pages'])->name('pages');
    Route::post('/pagine', [MetaOAuthController::class, 'select'])->name('select');
});
