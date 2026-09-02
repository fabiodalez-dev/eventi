<?php

declare(strict_types=1);

use App\Http\Controllers\Web\Account\EmailVerificationController;
use App\Http\Controllers\Web\Account\FeedController;
use App\Http\Controllers\Web\Account\FollowController;
use App\Http\Controllers\Web\Account\LoginController;
use App\Http\Controllers\Web\Account\MagicLinkController;
use App\Http\Controllers\Web\Account\NotificationSettingsController;
use App\Http\Controllers\Web\Account\PasswordResetController;
use App\Http\Controllers\Web\Account\ProfileController;
use App\Http\Controllers\Web\Account\RegisterController;
use App\Http\Controllers\Web\Account\SavedController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Account, salvataggi e feed (§15)
|--------------------------------------------------------------------------
|
| Queste rotte stanno **fuori** dai due gruppi del sito pubblico: non
| appartengono a una città. Il feed e i salvataggi parlano della città servita
| come tutto il resto, ma `/padova/il-mio-feed` non sarebbe un indirizzo più
| vero — sarebbe lo stesso feed con un prefisso in più.
|
| La rotta di accesso si chiama `login` senza altri prefissi perché è il nome
| che Laravel cerca quando il middleware `auth` deve rimandare qualcuno da
| qualche parte: chiamarla altrimenti significherebbe dichiarare quel
| reindirizzamento a mano in un punto lontano da qui.
*/

Route::middleware('guest')->group(function (): void {
    Route::get('/registrati', [RegisterController::class, 'create'])->name('account.register');
    Route::post('/registrati', [RegisterController::class, 'store'])
        ->middleware('throttle:public-forms')
        ->name('account.register.store');

    Route::get('/accedi', [LoginController::class, 'create'])->name('login');
    Route::post('/accedi', [LoginController::class, 'store'])
        ->middleware('throttle:account-auth')
        ->name('account.login.store');

    Route::get('/accedi/collegamento', [MagicLinkController::class, 'create'])->name('account.magic-link');
    Route::post('/accedi/collegamento', [MagicLinkController::class, 'store'])
        ->middleware('throttle:account-auth')
        ->name('account.magic-link.store');

    /*
     * Reimpostare la password (§15.2).
     *
     * L'indirizzo `/reimposta-password` non e' una scelta libera: e' quello
     * che `ResetPasswordLink` mette nei messaggi quando
     * `API_PASSWORD_RESET_URL` non e' impostato — cioe' in produzione. Finche'
     * questa rotta non e' esistita, quel collegamento portava a un 404.
     *
     * Lo stesso `throttle` dell'accesso: chiedere collegamenti a raffica su
     * indirizzi altrui e' un modo per riempire caselle di posta che non sono
     * tue.
     */
    Route::get('/password-dimenticata', [PasswordResetController::class, 'create'])
        ->name('account.password.request');
    Route::post('/password-dimenticata', [PasswordResetController::class, 'store'])
        ->middleware('throttle:account-auth')
        ->name('account.password.email');

    Route::get('/reimposta-password', [PasswordResetController::class, 'edit'])
        ->name('account.password.reset');
    Route::post('/reimposta-password', [PasswordResetController::class, 'update'])
        ->middleware('throttle:account-auth')
        ->name('account.password.update');

    /*
     * Il collegamento di accesso: la firma la verifica il middleware, il
     * resto — che la password non sia cambiata nel frattempo — il controller.
     */
    Route::get('/accedi/collegamento/{user}', [MagicLinkController::class, 'login'])
        ->middleware('signed')
        ->whereNumber('user')
        ->name('account.magic-link.login');
});

/*
 * La conferma dell'indirizzo non pretende una sessione: chi apre il messaggio
 * dal telefono può non essere collegato lì, e la firma del collegamento è già
 * una prova di identità. Il nome della rotta è quello che Laravel si aspetta.
 */
Route::get('/email/verifica/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware('signed')
    ->whereNumber('id')
    ->name('verification.verify');

/*
 * Le notifiche si governano **senza accesso** (§15.9): l'indirizzo è firmato e
 * a scadenza, e questo è tutto ciò che serve a chi vuole far smettere le email
 * che riceve. La disiscrizione risponde anche in `POST`, che è il verbo con
 * cui i client di posta chiamano `List-Unsubscribe` da soli (RFC 8058).
 */
Route::middleware('signed')->group(function (): void {
    Route::get('/notifiche/preferenze/{user}', [NotificationSettingsController::class, 'edit'])
        ->whereNumber('user')
        ->name('notifications.preferences');

    Route::patch('/notifiche/preferenze/{user}', [NotificationSettingsController::class, 'update'])
        ->whereNumber('user')
        ->name('notifications.preferences.update');

    Route::get('/notifiche/disiscriviti/{user}/{type}', [NotificationSettingsController::class, 'unsubscribe'])
        ->whereNumber('user')
        ->name('notifications.unsubscribe');

    Route::post('/notifiche/disiscriviti/{user}/{type}', [NotificationSettingsController::class, 'unsubscribe'])
        ->whereNumber('user')
        ->name('notifications.unsubscribe.submit');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/esci', [LoginController::class, 'destroy'])->name('account.logout');

    Route::get('/email/verifica', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::post('/email/verifica', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:account-auth')
        ->name('account.verification.send');

    Route::get('/il-mio-profilo', [ProfileController::class, 'edit'])->name('account.profile');
    Route::patch('/il-mio-profilo', [ProfileController::class, 'update'])->name('account.profile.update');
    Route::get('/il-mio-profilo/dati', [ProfileController::class, 'export'])->name('account.profile.export');
    Route::delete('/il-mio-profilo', [ProfileController::class, 'destroy'])->name('account.profile.destroy');

    Route::get('/il-mio-feed', FeedController::class)->name('account.feed');

    Route::get('/i-miei-salvataggi', [SavedController::class, 'index'])->name('account.saved');

    /*
     * `salvataggi/unisci` prima di `salvataggi/{occurrence}`: dichiarata dopo,
     * la rotta con il segnaposto se la mangerebbe — e per di più con un verbo
     * diverso, quindi l'errore sarebbe un 405 invece di un 404.
     */
    Route::post('/salvataggi/unisci', [SavedController::class, 'merge'])->name('account.saved.merge');
    Route::post('/salvataggi', [SavedController::class, 'store'])->name('account.saved.store');
    Route::delete('/salvataggi/{occurrence}', [SavedController::class, 'destroy'])
        ->whereNumber('occurrence')
        ->name('account.saved.destroy');

    Route::post('/segui', [FollowController::class, 'store'])->name('account.follows.store');
    Route::delete('/segui/{type}/{id}', [FollowController::class, 'destroy'])
        ->whereNumber('id')
        ->name('account.follows.destroy');
});
