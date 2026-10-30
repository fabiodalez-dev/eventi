<?php

declare(strict_types=1);

use App\Http\Controllers\Web\Account\AppearanceController;
use App\Http\Controllers\Web\Account\AttendedController;
use App\Http\Controllers\Web\Account\CollectionController;
use App\Http\Controllers\Web\Account\ContentPreferencesController;
use App\Http\Controllers\Web\Account\EmailVerificationController;
use App\Http\Controllers\Web\Account\FeedController;
use App\Http\Controllers\Web\Account\FollowController;
use App\Http\Controllers\Web\Account\LoginController;
use App\Http\Controllers\Web\Account\MagicLinkController;
use App\Http\Controllers\Web\Account\NotificationInterestsController;
use App\Http\Controllers\Web\Account\NotificationSettingsController;
use App\Http\Controllers\Web\Account\OneTimeAlertController;
use App\Http\Controllers\Web\Account\PasswordResetController;
use App\Http\Controllers\Web\Account\ProfileController;
use App\Http\Controllers\Web\Account\PushSubscriptionController;
use App\Http\Controllers\Web\Account\RegisterController;
use App\Http\Controllers\Web\Account\SavedCalendarController;
use App\Http\Controllers\Web\Account\SavedController;
use App\Http\Controllers\Web\Account\SavedSearchController;
use App\Http\Controllers\Web\GoogleCalendarController;
use App\Http\Controllers\Web\PresenceController;
use App\Http\Middleware\BlockWhileImpersonating;
use App\Http\Middleware\PersonalizeDiscovery;
use App\Http\Middleware\RequireConfirmedAccount;
use App\Http\Middleware\TicketingPrivacy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

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

/*
 * Il pannello delle date salvate, quello che si apre dall'icona in testata.
 *
 * Sta **fuori** da `auth`, e non è una svista: il cuore funziona al primo
 * click senza registrazione (§15.1) e le date di chi non ha un account vivono
 * nel `localStorage`. Finché questa rotta non è esistita, chi salvava da
 * anonimo non aveva alcun posto dove rivedere ciò che aveva salvato — la
 * pagina `/i-miei-salvataggi` chiede l'accesso, e su schermo largo non c'era
 * nemmeno un collegamento.
 *
 * Da anonimi gli identificativi arrivano dall'indirizzo. Non è un rischio:
 * `EventOccurrenceQuery::for()` guarda **solo eventi pubblicati della città
 * servita**, quindi un identificativo inventato non restituisce niente e non
 * esiste modo di farsi dire qualcosa che non sia già pubblico.
 */
Route::get('/salvataggi/pannello', [SavedController::class, 'panel'])
    ->middleware('throttle:60,1,saved-panel')
    ->name('account.saved.panel');

Route::get('/aspetto', [AppearanceController::class, 'index'])->name('appearance');
Route::patch('/aspetto', [AppearanceController::class, 'update'])->middleware(['auth', 'throttle:60,1,appearance'])->name('appearance.update');

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
        ->middleware(['throttle:account-auth', 'throttle:outbound-email'])
        ->name('account.magic-link.store');

    /*
     * Il collegamento di accesso: la firma la verifica il middleware, il
     * resto — che la password non sia cambiata nel frattempo — il controller.
     * Aperto in un browser diverso da quello che l'ha chiesto, la GET mostra
     * solo la conferma: entra il POST, con CSRF, verso lo stesso indirizzo.
     */
    Route::get('/accedi/collegamento/{user}', [MagicLinkController::class, 'show'])
        ->middleware('signed')
        ->whereNumber('user')
        ->name('account.magic-link.login');
    Route::post('/accedi/collegamento/{user}', [MagicLinkController::class, 'login'])
        ->middleware('signed')
        ->whereNumber('user')
        ->name('account.magic-link.confirm');
});

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
 *
 * Fuori dal gruppo `guest`: chi entra sempre con il collegamento non ha mai
 * scelto una password, e la cancellazione dell'account la chiede. Il
 * suggerimento sotto quel campo porta qui, e da collegati un rimando altrove
 * lo renderebbe falso.
 */
Route::get('/password-dimenticata', [PasswordResetController::class, 'create'])
    ->name('account.password.request');
Route::post('/password-dimenticata', [PasswordResetController::class, 'store'])
    ->middleware(['throttle:account-auth', 'throttle:outbound-email'])
    ->name('account.password.email');

Route::get('/reimposta-password', [PasswordResetController::class, 'edit'])
    ->name('account.password.reset');
Route::post('/reimposta-password', [PasswordResetController::class, 'update'])
    ->middleware('throttle:account-auth')
    ->name('account.password.update');

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
        ->middleware(BlockWhileImpersonating::class)
        ->name('notifications.preferences.update');

    Route::get('/notifiche/disiscriviti/{user}/{type}', [NotificationSettingsController::class, 'unsubscribe'])
        ->whereNumber('user')
        ->name('notifications.unsubscribe');

    Route::post('/notifiche/disiscriviti/{user}/{type}', [NotificationSettingsController::class, 'unsubscribe'])
        ->whereNumber('user')
        ->name('notifications.unsubscribe.submit');
});

/*
 * Durante l'impersonificazione l'amministratore guarda l'area personale ma
 * non scrive a nome della persona (`BlockWhileImpersonating`): l'uscita
 * resta sempre libera.
 */
Route::middleware(['auth', RequireConfirmedAccount::class, BlockWhileImpersonating::class])->group(function (): void {
    Route::prefix('il-mio-calendario/google')->name('google-calendar.')->middleware(TicketingPrivacy::class)->group(function (): void {
        Route::get('/', [GoogleCalendarController::class, 'index'])->name('index');
        Route::get('/da-app/{user}', [GoogleCalendarController::class, 'mobile'])->whereNumber('user')->middleware('signed')->name('mobile');
        Route::post('/collega', [GoogleCalendarController::class, 'connect'])->middleware('throttle:10,1,calendar-google-manage')->name('connect');
        Route::get('/callback', [GoogleCalendarController::class, 'callback'])->middleware('throttle:30,1,calendar-google-callback')->name('callback');
        Route::patch('/', [GoogleCalendarController::class, 'update'])->middleware('throttle:10,1,calendar-google-manage')->name('update');
        Route::delete('/', [GoogleCalendarController::class, 'disconnect'])->middleware('throttle:10,1,calendar-google-manage')->name('disconnect');
    });
    Route::get('/profilo/interessi', [ContentPreferencesController::class, 'index'])->name('account.content-preferences');
    Route::patch('/profilo/interessi', [ContentPreferencesController::class, 'update'])->name('account.content-preferences.update');
    foreach (['disponibilita' => ['availability', 'availability-alerts'], 'apertura' => ['opening', 'opening-alerts'], 'edizione' => ['edition', 'edition-alerts']] as $path => [$kind, $name]) {
        Route::post('/avvisi-'.$path.'/{subject}', [OneTimeAlertController::class, 'store'])->defaults('kind', $kind)->whereNumber('subject')->middleware('throttle:30,1,'.$name)->name('account.'.$name.'.store');
        Route::delete('/avvisi-'.$path.'/{subject}', [OneTimeAlertController::class, 'destroy'])->defaults('kind', $kind)->whereNumber('subject')->name('account.'.$name.'.destroy');
    }
    Route::post('/locali-nascosti/{venue}', [ContentPreferencesController::class, 'hideVenue'])->whereNumber('venue')->middleware('throttle:30,1,hidden-venues')->name('account.hidden-venues.store');
    Route::delete('/locali-nascosti/{venue}', [ContentPreferencesController::class, 'showVenue'])->whereNumber('venue')->middleware('throttle:30,1,hidden-venues')->name('account.hidden-venues.destroy');
    Route::get('/notifiche/interessi', [NotificationInterestsController::class, 'index'])->name('account.notifications.interests');
    Route::post('/sono-qui/data/{occurrence}', [PresenceController::class, 'store'])->whereNumber('occurrence')->middleware('throttle:20,60,presence')->name('presence.store');
    Route::delete('/sono-qui/{token}', [PresenceController::class, 'destroy'])->where('token', '[A-Za-z0-9]{32}')->name('presence.destroy');
    Route::get('/le-mie-serate', AttendedController::class)->name('account.attended');
    Route::get('/le-mie-raccolte', [CollectionController::class, 'index'])->name('account.collections.index');
    Route::post('/le-mie-raccolte', [CollectionController::class, 'store'])->middleware('throttle:30,1,collections')->name('account.collections.store');
    Route::get('/le-mie-raccolte/{collection}', [CollectionController::class, 'show'])->whereNumber('collection')->name('account.collections.show');
    Route::patch('/le-mie-raccolte/{collection}', [CollectionController::class, 'update'])->whereNumber('collection')->middleware('throttle:30,1,collections')->name('account.collections.update');
    Route::delete('/le-mie-raccolte/{collection}', [CollectionController::class, 'destroy'])->whereNumber('collection')->name('account.collections.destroy');
    Route::post('/le-mie-raccolte/{collection}/date', [CollectionController::class, 'addItem'])->whereNumber('collection')->middleware('throttle:60,1,collection-items')->name('account.collections.items.store');
    Route::delete('/le-mie-raccolte/{collection}/date/{occurrence}', [CollectionController::class, 'removeItem'])->whereNumber('collection')->whereNumber('occurrence')->name('account.collections.items.destroy');
    Route::post('/ricerche-salvate', [SavedSearchController::class, 'store'])->middleware('throttle:20,1,saved-search')->name('account.searches.store');
    Route::delete('/ricerche-salvate/{search}', [SavedSearchController::class, 'destroy'])->whereNumber('search')->middleware('throttle:20,1,saved-search')->name('account.searches.destroy');
    Route::patch('/notifiche/interessi', [NotificationInterestsController::class, 'update'])->name('account.notifications.interests.update');
    Route::post('/esci', [LoginController::class, 'destroy'])->withoutMiddleware(BlockWhileImpersonating::class)->name('account.logout');

    /*
     * La stessa pagina delle preferenze, raggiungibile da dentro il sito.
     *
     * Fin qui esisteva solo dietro un collegamento firmato, cioe' **dentro le
     * email**: un disegno giusto per la disiscrizione, che deve funzionare
     * senza login, ma che chiudeva un cerchio per l'iscrizione. Per ricevere
     * il riepilogo del fine settimana serve il consenso; il consenso si da'
     * da quella pagina; a quella pagina si arriva da un'email che non si
     * riceve perche' manca il consenso. Nessuno poteva iscriversi.
     *
     * Si firma al volo per se' stessi e si rimanda li': una pagina sola, un
     * controller solo, e la firma resta l'unico modo di arrivarci — anche per
     * chi ha fatto l'accesso.
     */
    Route::get('/notifiche', function (): RedirectResponse {
        $utente = Auth::user();

        abort_if($utente === null, 403);

        return redirect()->to(URL::temporarySignedRoute(
            'notifications.preferences',
            now()->addHour(),
            ['user' => $utente->getKey()],
        ));
    })->name('account.notifications');

    /*
     * L'iscrizione del browser al canale push (§15.6, D54).
     *
     * Sta qui, dentro `auth`, e non nel gruppo firmato poco sopra: il
     * collegamento firmato arriva per email e puo' essere inoltrato, e finora
     * permetteva soltanto di far smettere le notifiche. Iscrivere un browser
     * fa il contrario — manda il contenuto delle notifiche future su uno
     * schermo — e quel gesto vuole una sessione, non un indirizzo che gira.
     *
     * Non riusa `POST /v1/me/devices`, che fa la stessa scrittura: quella
     * rotta e' dietro `auth:sanctum` senza `statefulApi()`, quindi da una
     * pagina a sessione risponde 401. Vedi `PushSubscriptionController`.
     */
    /*
     * Un limitatore come su ogni altra scrittura di questo file: era l'unica
     * senza, e ogni chiamata puo' depositare una riga in `devices`.
     */
    Route::post('/notifiche/push', [PushSubscriptionController::class, 'store'])
        ->middleware('throttle:10,1,push-subscription')
        ->name('account.push.store');

    Route::delete('/notifiche/push', [PushSubscriptionController::class, 'destroy'])
        ->middleware('throttle:10,1,push-subscription')
        ->name('account.push.destroy');

    Route::get('/email/verifica', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::post('/email/verifica', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:account-auth')
        ->name('account.verification.send');

    Route::get('/il-mio-profilo', [ProfileController::class, 'edit'])->name('account.profile');
    Route::patch('/il-mio-profilo', [ProfileController::class, 'update'])->name('account.profile.update');
    /* Lo scarico porta tutto il dossier personale in un colpo solo: cinque
       l'ora bastano a chi lo chiede davvero. */
    Route::get('/il-mio-profilo/dati', [ProfileController::class, 'export'])->middleware([BlockWhileImpersonating::class.':all', 'throttle:5,60,account-export'])->name('account.profile.export');
    /* La password attuale qui è una prova: senza limite, chi trova una
       sessione aperta la tirerebbe a indovinare quanto vuole. */
    Route::delete('/il-mio-profilo', [ProfileController::class, 'destroy'])->middleware('throttle:5,15,account-delete')->name('account.profile.destroy');

    Route::get('/il-mio-feed', FeedController::class)->middleware(PersonalizeDiscovery::class)->name('account.feed');

    Route::get('/i-miei-salvataggi', [SavedController::class, 'index'])->name('account.saved');
    Route::get('/salvataggi/stato', [SavedController::class, 'state'])->name('account.saved.state');
    Route::get('/i-miei-salvataggi/calendario.ics', [SavedCalendarController::class, 'download'])
        ->middleware('throttle:60,1,saved-calendar')->name('account.saved.calendar');

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
