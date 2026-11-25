<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Management\AnalyticsChannelController;
use App\Http\Controllers\Api\V1\Management\AnalyticsController;
use App\Http\Controllers\Api\V1\Management\EventAnalyticsController;
use Illuminate\Support\Facades\Route;

/*
 * Statistiche (P5): «Statistiche», «Statistiche in dettaglio» con tabelle ed
 * esportazioni, la pagina di un evento, locale o organizzatore, le statistiche
 * delle date di un evento, i link brevi di un evento, i canali propri del
 * locale.
 *
 * Incluso due volte da `routes/api.php`, dentro `venues/{venue}` e dentro
 * `organizers/{organizer}`: `$subjectType` (`venue` o `organizer`) dice in
 * quale dei due. Il gruppo porta già `auth:sanctum`, `TicketingPrivacy` e
 * `ResolveManagementSubject`. I canali propri esistono solo per il locale,
 * come la sezione del pannello.
 *
 * I rapporti sono letture pesanti su un hosting condiviso: un secchio per
 * persona (`management-analytics`, 60 al minuto) e uno più stretto per i
 * file (`management-analytics-export`, 10 al minuto). L'app non aggiorna da
 * sola: si rilegge solo quando la persona cambia periodo o filtri.
 *
 * @var string $subjectType
 */
Route::middleware('throttle:60,1,management-analytics')->group(function () use ($subjectType): void {
    Route::get('/analytics/summary', [AnalyticsController::class, 'summary'])->name('analytics.summary');
    Route::get('/analytics/report', [AnalyticsController::class, 'report'])->name('analytics.report');
    Route::get('/analytics/report/table', [AnalyticsController::class, 'reportTable'])->name('analytics.report.table');
    Route::get('/analytics/events', [AnalyticsController::class, 'events'])->name('analytics.events');
    Route::get('/analytics/subjects/{type}/{id}', [AnalyticsController::class, 'subject'])
        ->where(['type' => 'event|venue|organizer', 'id' => '[0-9]+'])->name('analytics.subjects.show');
    Route::get('/analytics/subjects/{type}/{id}/table', [AnalyticsController::class, 'subjectTable'])
        ->where(['type' => 'event|venue|organizer', 'id' => '[0-9]+'])->name('analytics.subjects.table');

    Route::get('/events/{event}/dates/analytics', [EventAnalyticsController::class, 'dates'])->whereNumber('event')->name('events.dates.analytics');
    Route::get('/events/{event}/dates/{occurrence}/analytics', [EventAnalyticsController::class, 'occurrence'])
        ->whereNumber(['event', 'occurrence'])->name('events.dates.occurrence-analytics');
    Route::get('/events/{event}/share-links', [EventAnalyticsController::class, 'shareLinks'])->whereNumber('event')->name('events.share-links');

    if ($subjectType === 'venue') {
        Route::get('/analytics/channels', [AnalyticsChannelController::class, 'index'])->name('analytics.channels.index');
        Route::post('/analytics/channels', [AnalyticsChannelController::class, 'store'])
            ->middleware('throttle:30,1,management-analytics-channels')->name('analytics.channels.store');
    }
});

Route::middleware('throttle:10,1,management-analytics-export')->group(function (): void {
    Route::get('/analytics/report/export', [AnalyticsController::class, 'reportExport'])->name('analytics.report.export');
    Route::get('/analytics/subjects/{type}/{id}/export', [AnalyticsController::class, 'subjectExport'])
        ->where(['type' => 'event|venue|organizer', 'id' => '[0-9]+'])->name('analytics.subjects.export');
});
