<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Management\SocialStudioController;
use Illuminate\Support\Facades\Route;

/*
 * Grafiche social (P10): lo studio delle grafiche del locale e l'anteprima
 * della grafica di una data.
 *
 * Incluso due volte da `routes/api.php`, dentro `venues/{venue}` e dentro
 * `organizers/{organizer}`: `$subjectType` (`venue` o `organizer`) dice in
 * quale dei due. Il gruppo porta già `auth:sanctum`, `TicketingPrivacy` e
 * `ResolveManagementSubject`.
 *
 * Lo studio esiste solo per il locale, come la pagina «Social» del
 * pannello; l'anteprima della grafica di una data vale per entrambi
 * (l'organizzazione la apre dall'elenco delle date, W-DT-12) e consuma il
 * secchio del sito (`social-preview`). Le letture stanno in
 * `management-social` (60 al minuto per persona); la preparazione, che
 * disegna fino a cento immagini, in `management-social-generate` (10 al
 * minuto).
 *
 * @var string $subjectType
 */
Route::get('/events/{event}/dates/{occurrence}/social-preview', [SocialStudioController::class, 'preview'])
    ->whereNumber(['event', 'occurrence'])->middleware('throttle:120,1,social-preview')->name('dates.social-preview');

if ($subjectType === 'venue') {
    Route::prefix('social')->name('social.')->group(function (): void {
        Route::middleware('throttle:60,1,management-social')->group(function (): void {
            Route::get('/catalog', [SocialStudioController::class, 'catalog'])->name('catalog');
            Route::get('/calendar', [SocialStudioController::class, 'calendar'])->name('calendar');
            Route::get('/day', [SocialStudioController::class, 'day'])->name('day');
            Route::get('/events', [SocialStudioController::class, 'events'])->name('events');
            Route::get('/batches', [SocialStudioController::class, 'batches'])->name('batches.index');
            Route::get('/batches/{batch}', [SocialStudioController::class, 'show'])->whereUuid('batch')->name('batches.show');
            Route::get('/batches/{batch}/images/{index}', [SocialStudioController::class, 'image'])
                ->whereUuid('batch')->whereNumber('index')->name('batches.image');
            Route::get('/batches/{batch}/zip', [SocialStudioController::class, 'zip'])->whereUuid('batch')->name('batches.zip');
        });
        Route::post('/batches', [SocialStudioController::class, 'store'])
            ->middleware('throttle:10,1,management-social-generate')->name('batches.store');
    });
}
