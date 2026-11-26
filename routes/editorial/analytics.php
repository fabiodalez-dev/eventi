<?php

declare(strict_types=1);

/*
 * R10 — Statistiche, SEO, ricerche (R-ST-01…06).
 *
 * Incluso da `routes/api.php` dentro `v1/editorial` (nome `api.v1.editorial.`),
 * nel gruppo che porta già `auth:sanctum`, `TicketingPrivacy`,
 * `ResolveEditorialDesk` e `RequireEditorialUnlock`. Percorsi di questo
 * pacchetto: analytics, seo, search-misses.
 *
 * Ogni rotta ha un nome (`<area>.<azione>`) e un `throttle:N,M,<nome>`; le
 * azioni pericolose (decisione 4) aggiungono
 * `->middleware(RequireRecentPassword::class)`. Le regole sono nel contratto
 * delle fondamenta e le controlla `EditorialRoutesTest`.
 */

use App\Http\Controllers\Api\V1\Editorial\AnalyticsController;
use App\Http\Controllers\Api\V1\Editorial\SearchMissController;
use App\Http\Controllers\Api\V1\Editorial\SeoController;
use App\Http\Middleware\RequireRecentPassword;
use Illuminate\Support\Facades\Route;

/*
 * Statistiche condivisioni (R-ST-01…03): admin e super admin. I rapporti
 * sono letture pesanti su un hosting condiviso: un secchio per persona
 * (`editorial-analytics`, 60 al minuto) e uno più stretto per i file
 * (`editorial-analytics-export`, 10 al minuto), come la gestione.
 */
Route::get('/analytics/report', [AnalyticsController::class, 'report'])->middleware('throttle:60,1,editorial-analytics')->name('analytics.report');
Route::get('/analytics/report/table', [AnalyticsController::class, 'reportTable'])->middleware('throttle:60,1,editorial-analytics')->name('analytics.report.table');
Route::get('/analytics/report/export', [AnalyticsController::class, 'reportExport'])->middleware('throttle:10,1,editorial-analytics-export')->name('analytics.report.export');
Route::get('/analytics/events', [AnalyticsController::class, 'events'])->middleware('throttle:60,1,editorial-analytics')->name('analytics.events');
Route::get('/analytics/venues', [AnalyticsController::class, 'venues'])->middleware('throttle:60,1,editorial-analytics')->name('analytics.venues');
Route::get('/analytics/organizers', [AnalyticsController::class, 'organizers'])->middleware('throttle:60,1,editorial-analytics')->name('analytics.organizers');
Route::get('/analytics/subjects/{type}/{id}', [AnalyticsController::class, 'subject'])
    ->where(['type' => 'event|venue|organizer', 'id' => '[0-9]+'])->middleware('throttle:60,1,editorial-analytics')->name('analytics.subjects.show');
Route::get('/analytics/subjects/{type}/{id}/table', [AnalyticsController::class, 'subjectTable'])
    ->where(['type' => 'event|venue|organizer', 'id' => '[0-9]+'])->middleware('throttle:60,1,editorial-analytics')->name('analytics.subjects.table');
Route::get('/analytics/subjects/{type}/{id}/export', [AnalyticsController::class, 'subjectExport'])
    ->where(['type' => 'event|venue|organizer', 'id' => '[0-9]+'])->middleware('throttle:10,1,editorial-analytics-export')->name('analytics.subjects.export');

/*
 * SEO e qualità del catalogo (R-ST-04, R-ST-05): admin e super admin. Il
 * codice di Search Console è pubblico nella pagina, ma scriverlo fa di chi lo
 * scrive un proprietario verificato del sito: dall'app chiede la password
 * recente, e ogni cambio (anche dal pannello) va nel registro di sicurezza.
 */
Route::get('/seo', [SeoController::class, 'show'])->middleware('throttle:60,1,editorial-seo')->name('seo.show');
Route::get('/seo/audit', [SeoController::class, 'audit'])->middleware('throttle:60,1,editorial-seo')->name('seo.audit');
// Chi scrive il codice diventa proprietario verificato del sito su Search Console: password recente.
Route::put('/seo/verification', [SeoController::class, 'verification'])
    ->middleware([RequireRecentPassword::class, 'throttle:20,1,editorial-seo-verification'])->name('seo.verification');

/*
 * Ricerche senza risultati (R-ST-06): moderatore, admin e super admin.
 * Togliere una riga non è pericoloso: `remove`, non `destroy`.
 */
Route::get('/search-misses', [SearchMissController::class, 'index'])->middleware('throttle:60,1,editorial-search-misses')->name('search-misses.index');
Route::post('/search-misses/bulk-delete', [SearchMissController::class, 'bulkRemove'])->middleware('throttle:20,1,editorial-search-misses-remove')->name('search-misses.bulk-remove');
Route::delete('/search-misses/{miss}', [SearchMissController::class, 'remove'])->whereNumber('miss')->middleware('throttle:60,1,editorial-search-misses-remove')->name('search-misses.remove');
