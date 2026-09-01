<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\MagicLinkController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\CalendarController;
use App\Http\Controllers\Api\V1\CityController;
use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\DiscoveryController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\MapController;
use App\Http\Controllers\Api\V1\Me\DeviceController;
use App\Http\Controllers\Api\V1\Me\ExportController;
use App\Http\Controllers\Api\V1\Me\FeedController;
use App\Http\Controllers\Api\V1\Me\FollowController;
use App\Http\Controllers\Api\V1\Me\NotificationController;
use App\Http\Controllers\Api\V1\Me\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\Me\ProfileController;
use App\Http\Controllers\Api\V1\Me\SavedController;
use App\Http\Controllers\Api\V1\OccurrenceController;
use App\Http\Controllers\Api\V1\PageController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SubmissionController;
use App\Http\Controllers\Api\V1\TaxonomyController;
use App\Http\Controllers\Api\V1\VenueController;
use App\Http\Middleware\Api\CacheJsonResponse;
use App\Http\Middleware\Api\ResolveApiCity;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API pubblica v1 (§13)
|--------------------------------------------------------------------------
|
| Base `/api/v1`. Il contratto è stabile: dentro la v1 non si tolgono campi e
| non se ne cambia il significato.
|
| Tre gruppi, tre regimi diversi:
|
| - le **letture pubbliche** portano `ETag` e `Cache-Control` e rispondono 304
|   a chi ha già la stessa versione;
| - le **scritture pubbliche** (proposte e segnalazioni) non si mettono in
|   cache e passano dal limite di frequenza dei moduli pubblici (§14.7);
| - l'**autenticazione** ha un limite proprio e molto più stretto (§16): sono
|   gli indirizzi che qualcuno proverà a forzare.
|
| La città la sceglie `ResolveApiCity` dal parametro `city`; senza, vale la
| città predefinita. Nessun controller ha quindi un parametro in più.
*/

Route::prefix('v1')
    ->middleware(ResolveApiCity::class)
    ->name('api.v1.')
    ->group(function (): void {

        Route::middleware(CacheJsonResponse::class)->group(function (): void {
            Route::get('/config', ConfigController::class)->name('config');

            Route::get('/cities', [CityController::class, 'index'])->name('cities.index');
            Route::get('/cities/{slug}', [CityController::class, 'show'])->name('cities.show');

            /*
             * L'ordine conta: `/events/{slug}` è dichiarata per ultima,
             * altrimenti si mangerebbe `/events/{slug}/similar`.
             */
            Route::get('/events', [EventController::class, 'index'])->name('events.index');
            Route::get('/events/{slug}/similar', [EventController::class, 'similar'])->name('events.similar');
            Route::get('/events/{slug}/occurrences', [EventController::class, 'occurrences'])->name('events.occurrences');
            Route::get('/events/{slug}', [EventController::class, 'show'])->name('events.show');

            Route::get('/occurrences/{occurrence}', [OccurrenceController::class, 'show'])
                ->whereNumber('occurrence')
                ->name('occurrences.show');

            Route::get('/calendar', CalendarController::class)->name('calendar');

            Route::get('/venues', [VenueController::class, 'index'])->name('venues.index');
            Route::get('/venues/{slug}/events', [VenueController::class, 'events'])->name('venues.events');
            Route::get('/venues/{slug}/past', [VenueController::class, 'past'])->name('venues.past');
            Route::get('/venues/{slug}', [VenueController::class, 'show'])->name('venues.show');

            Route::get('/map/occurrences', MapController::class)->name('map.occurrences');

            Route::get('/search', SearchController::class)->name('search');

            /* Tassonomie complete: /config ne porta un estratto, questi
               portano i conteggi per citta e i sinonimi (§7.4, §7.5). */
            Route::get('/categories', [TaxonomyController::class, 'categories'])->name('categories.index');
            Route::get('/categories/{slug}', [TaxonomyController::class, 'category'])->name('categories.show');
            Route::get('/tags', [TaxonomyController::class, 'tags'])->name('tags.index');
            Route::get('/tags/{slug}', [TaxonomyController::class, 'tag'])->name('tags.show');

            /* Le pagine legali dentro l'applicazione: gli store le pretendono. */
            Route::get('/pages', [PageController::class, 'index'])->name('pages.index');
            Route::get('/pages/{slug}', [PageController::class, 'show'])->name('pages.show');

            /* Filtro geografico e misure di §1, per non aprire l'app vuota. */
            Route::get('/areas', [DiscoveryController::class, 'areas'])->name('areas.index');
            Route::get('/stats', [DiscoveryController::class, 'stats'])->name('stats');

        });

        Route::middleware('throttle:public-forms')->group(function (): void {
            Route::post('/submissions', SubmissionController::class)->name('submissions.store');
            Route::post('/reports', ReportController::class)->name('reports.store');
        });

        Route::prefix('auth')->name('auth.')->group(function (): void {
            Route::middleware('throttle:api-auth')->group(function (): void {
                Route::post('/register', [AuthController::class, 'register'])->name('register');
                Route::post('/login', [AuthController::class, 'login'])->name('login');
                Route::post('/password/forgot', [PasswordController::class, 'forgot'])->name('password.forgot');
                Route::post('/password/reset', [PasswordController::class, 'reset'])->name('password.reset');
            });

            /*
             * L'accesso senza password e la verifica dell'email (§15.2) stanno
             * nello stesso limite stretto degli altri indirizzi di
             * autenticazione: sono due modi di entrare, e chi prova a forzarli
             * prova a entrare.
             */
            Route::middleware('throttle:api-auth')->group(function (): void {
                Route::post('/magic-link', MagicLinkController::class)->name('magic-link');
                Route::post('/verify-email', EmailVerificationController::class)->name('verify-email');
            });

            Route::post('/logout', [AuthController::class, 'logout'])
                ->middleware('auth:sanctum')
                ->name('logout');
        });

        /*
         * L'area personale (§15.8). Nessuna di queste risposte passa da
         * `CacheJsonResponse`: parlano di una persona sola e non possono
         * finire in una cache condivisa né portare un `ETag` che qualcuno
         * riuserebbe per un'altra.
         *
         * `DELETE /me` è dichiarata prima delle rotte figlie soltanto per
         * leggibilità: i percorsi non si sovrappongono.
         */
        Route::prefix('me')
            ->middleware('auth:sanctum')
            ->name('me.')
            ->group(function (): void {
                Route::get('/', [ProfileController::class, 'show'])->name('show');
                Route::patch('/', [ProfileController::class, 'update'])->name('update');
                Route::delete('/', [ProfileController::class, 'destroy'])->name('destroy');

                Route::get('/notification-preferences', [NotificationPreferenceController::class, 'show'])->name('preferences.show');
                Route::patch('/notification-preferences', [NotificationPreferenceController::class, 'update'])->name('preferences.update');

                /*
                 * `saved/merge` prima di `saved`: dichiarata dopo, la rotta
                 * con il segnaposto se la mangerebbe.
                 */
                Route::post('/saved/merge', [SavedController::class, 'merge'])->name('saved.merge');
                Route::get('/saved', [SavedController::class, 'index'])->name('saved.index');
                Route::post('/saved', [SavedController::class, 'store'])->name('saved.store');
                Route::delete('/saved/{occurrence}', [SavedController::class, 'destroy'])
                    ->whereNumber('occurrence')
                    ->name('saved.destroy');

                Route::get('/follows', [FollowController::class, 'index'])->name('follows.index');
                Route::post('/follows', [FollowController::class, 'store'])->name('follows.store');
                Route::delete('/follows/{type}/{id}', [FollowController::class, 'destroy'])
                    ->whereNumber('id')
                    ->name('follows.destroy');

                Route::get('/feed', FeedController::class)->name('feed');

                Route::post('/devices', [DeviceController::class, 'store'])->name('devices.store');
                Route::delete('/devices/{device}', [DeviceController::class, 'destroy'])
                    ->whereNumber('device')
                    ->name('devices.destroy');

                Route::get('/notifications', NotificationController::class)->name('notifications');
                Route::get('/export', ExportController::class)->name('export');
            });
    });
