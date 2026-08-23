<?php

declare(strict_types=1);

use App\Http\Controllers\Web\ImpersonationController;
use App\Http\Controllers\Web\WidgetController;
use App\Http\Middleware\ResolveCity;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
 * Le rotte del sito pubblico stanno in routes/public.php e sono registrate due
 * volte: senza prefisso per la città predefinita e sotto `/{city}` per tutte le
 * altre (§11.1). Il secondo gruppo arriva per ultimo, così `/eventi` resta la
 * rotta della città predefinita e non viene letto come "città eventi".
 */
Route::group([], base_path('routes/public.php'));

Route::prefix('{city}')
    ->where(['city' => '[a-z][a-z0-9-]*'])
    ->middleware(ResolveCity::class)
    ->name('city.')
    ->group(base_path('routes/public.php'));

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
