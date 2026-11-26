<?php

declare(strict_types=1);

/*
 * R9 — Social della redazione (R-SO-01…07, R-DB-10, R-WB-03, R-WB-04).
 *
 * Incluso da `routes/api.php` dentro `v1/editorial` (nome `api.v1.editorial.`),
 * nel gruppo che porta già `auth:sanctum`, `TicketingPrivacy`,
 * `ResolveEditorialDesk` e `RequireEditorialUnlock`. Percorsi di questo
 * pacchetto: social.
 *
 * Ogni rotta ha un nome (`<area>.<azione>`) e un `throttle:N,M,<nome>`; le
 * azioni pericolose (decisione 4) aggiungono
 * `->middleware(RequireRecentPassword::class)`. Le regole sono nel contratto
 * delle fondamenta e le controlla `EditorialRoutesTest`.
 */

use App\Http\Controllers\Api\V1\Editorial\SocialPublicationController;
use App\Http\Controllers\Api\V1\Editorial\SocialSettingsController;
use App\Http\Controllers\Api\V1\Editorial\SocialStudioController;
use App\Http\Middleware\RequireRecentPassword;
use Illuminate\Support\Facades\Route;

/*
 * Tutte per admin e super admin (`social_use`, come `Social::canAccess`); il
 * moderatore riceve 403. «Pubblica sui canali collegati», «Programma» e
 * «Riprova» mettono un post visibile a tutti sui canali della città: sono
 * azioni pericolose (decisione 4) e chiedono la password scritta negli
 * ultimi 15 minuti (`RequireRecentPassword`, 423 `PASSWORD_REQUIRED`), oltre
 * alla conferma nell'app come `wire:confirm` della pagina. Riprogrammare e
 * annullare una programmazione non pubblicano niente. Chiedono la password
 * recente anche le impostazioni (revisione dell'ondata 3: accendere
 * l'automatico o cambiare il testo pubblica ogni giorno senza passare da
 * «Pubblica») e «Scollega Meta», che si rimedia solo rifacendo il
 * collegamento dal sito.
 * L'anteprima consuma il secchio del sito (`social-preview`).
 */
Route::prefix('social')->name('social.')->group(function (): void {
    Route::middleware('throttle:60,1,editorial-social')->group(function (): void {
        Route::get('/catalog', [SocialStudioController::class, 'catalog'])->name('catalog');
        Route::get('/calendar', [SocialStudioController::class, 'calendar'])->name('calendar');
        Route::get('/day', [SocialStudioController::class, 'day'])->name('day');
        Route::get('/today', [SocialStudioController::class, 'today'])->name('today');
        Route::get('/batches', [SocialStudioController::class, 'batches'])->name('batches.index');
        Route::get('/batches/{batch}', [SocialStudioController::class, 'show'])->whereUuid('batch')->name('batches.show');
        Route::get('/batches/{batch}/images/{index}', [SocialStudioController::class, 'image'])
            ->whereUuid('batch')->whereNumber('index')->name('batches.image');
        Route::get('/batches/{batch}/zip', [SocialStudioController::class, 'zip'])->whereUuid('batch')->name('batches.zip');
        Route::get('/publications', [SocialPublicationController::class, 'index'])->name('publications.index');
        Route::get('/settings', [SocialSettingsController::class, 'show'])->name('settings.show');
        Route::get('/meta/connect', [SocialSettingsController::class, 'connect'])->name('meta.connect');
    });
    Route::get('/dates/{occurrence}/preview', [SocialStudioController::class, 'preview'])
        ->whereNumber('occurrence')->middleware('throttle:120,1,social-preview')->name('preview');

    Route::middleware('throttle:10,1,editorial-social-generate')->group(function (): void {
        Route::post('/batches', [SocialStudioController::class, 'store'])->name('batches.store');
        Route::post('/today', [SocialStudioController::class, 'prepareToday'])->name('today.prepare');
    });

    Route::middleware('throttle:20,1,editorial-social-publish')->group(function (): void {
        Route::post('/batches/{batch}/publish', [SocialPublicationController::class, 'publish'])->whereUuid('batch')
            ->middleware(RequireRecentPassword::class)->name('batches.publish');
        Route::post('/batches/{batch}/schedule', [SocialPublicationController::class, 'schedule'])->whereUuid('batch')
            ->middleware(RequireRecentPassword::class)->name('batches.schedule');
        Route::patch('/publications/{publication}', [SocialPublicationController::class, 'reschedule'])->whereNumber('publication')->name('publications.reschedule');
        Route::post('/publications/{publication}/cancel', [SocialPublicationController::class, 'cancel'])->whereNumber('publication')->name('publications.cancel');
        Route::post('/publications/{publication}/retry', [SocialPublicationController::class, 'retry'])->whereNumber('publication')
            ->middleware(RequireRecentPassword::class)->name('publications.retry');
    });

    Route::middleware('throttle:20,1,editorial-social-settings')->group(function (): void {
        Route::put('/settings', [SocialSettingsController::class, 'update'])->middleware(RequireRecentPassword::class)->name('settings.update');
        Route::post('/meta/disconnect', [SocialSettingsController::class, 'disconnect'])->middleware(RequireRecentPassword::class)->name('meta.disconnect');
    });
    Route::middleware('throttle:10,1,editorial-social-verify')->group(function (): void {
        Route::post('/settings/verify', [SocialSettingsController::class, 'verify'])->name('settings.verify');
        Route::post('/settings/telegram/verify', [SocialSettingsController::class, 'verifyTelegram'])->name('settings.telegram');
    });
});
