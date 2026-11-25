<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Management\CalendarImportController;
use Illuminate\Support\Facades\Route;

/*
 * Import del calendario (P9, W-IM-01…07).
 *
 * Incluso due volte da `routes/api.php`, dentro `venues/{venue}` e dentro
 * `organizers/{organizer}`: `$subjectType` (`venue` o `organizer`) dice in
 * quale dei due. Il gruppo porta già `auth:sanctum`, `TicketingPrivacy` e
 * `ResolveManagementSubject`.
 *
 * Solo il locale: l'organizzazione non ha un calendario collegato (il
 * pannello `/organizza` non ha la pagina). Sotto `organizers/{organizer}`
 * queste rotte non esistono, e rispondono 404.
 *
 * Il collegamento è un `POST` e non un `PUT` perché porta il file CSV in
 * multipart, che PHP legge solo nei `POST`. La pagina del pannello non ha
 * limiti propri; qui l'anteprima e «Leggi adesso» scaricano un calendario
 * remoto (fino a 8 MB) e hanno un tetto per persona.
 *
 * @var string $subjectType
 */
if ($subjectType === 'venue') {
    Route::get('/import', [CalendarImportController::class, 'show'])->name('import.show');
    Route::post('/import/preview', [CalendarImportController::class, 'preview'])
        ->middleware('throttle:20,1,management-import-preview')->name('import.preview');
    Route::post('/import', [CalendarImportController::class, 'store'])
        ->middleware('throttle:20,1,management-import')->name('import.store');
    Route::post('/import/run', [CalendarImportController::class, 'run'])
        ->middleware('throttle:10,1,management-import-run')->name('import.run');
    Route::post('/import/pause', [CalendarImportController::class, 'pause'])
        ->middleware('throttle:20,1,management-import')->name('import.pause');
    Route::post('/import/resume', [CalendarImportController::class, 'resume'])
        ->middleware('throttle:20,1,management-import')->name('import.resume');
    Route::delete('/import', [CalendarImportController::class, 'destroy'])
        ->middleware('throttle:20,1,management-import')->name('import.destroy');
}
