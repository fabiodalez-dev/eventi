<?php

declare(strict_types=1);

/*
 * R8 — Promozione (R-SP-01…15).
 *
 * Incluso da `routes/api.php` dentro `v1/editorial` (nome `api.v1.editorial.`),
 * nel gruppo che porta già `auth:sanctum`, `TicketingPrivacy`,
 * `ResolveEditorialDesk` e `RequireEditorialUnlock`. Percorsi di questo
 * pacchetto: sponsorships, sponsorship-orders, sponsorship-grants, sponsorship-prices, sponsorship-banners.
 *
 * Ogni rotta ha un nome (`<area>.<azione>`) e un `throttle:N,M,<nome>`; le
 * azioni pericolose (decisione 4) aggiungono
 * `->middleware(RequireRecentPassword::class)`. Le regole sono nel contratto
 * delle fondamenta e le controlla `EditorialRoutesTest`.
 */

use App\Http\Controllers\Api\V1\Editorial\SponsorshipAnalyticsController;
use App\Http\Controllers\Api\V1\Editorial\SponsorshipBannerController;
use App\Http\Controllers\Api\V1\Editorial\SponsorshipController;
use App\Http\Controllers\Api\V1\Editorial\SponsorshipGrantController;
use App\Http\Controllers\Api\V1\Editorial\SponsorshipOrderController;
use App\Http\Controllers\Api\V1\Editorial\SponsorshipPriceController;
use App\Http\Middleware\RequireRecentPassword;
use Illuminate\Support\Facades\Route;

/*
 * Campagne (R-SP-01…06). I percorsi fissi (`export`, `sponsors`, `events`,
 * `prefill`, `estimate`, `analytics`, `bulk-destroy`) prima di
 * `{sponsorship}`, che è sempre un id numerico. Eliminare, anche in gruppo,
 * è pericoloso (decisione 4): password recente.
 */
Route::get('/sponsorships', [SponsorshipController::class, 'index'])->middleware('throttle:60,1,editorial-sponsorships')->name('sponsorships.index');
Route::get('/sponsorships/export', [SponsorshipController::class, 'export'])->middleware('throttle:10,1,editorial-sponsorships-export')->name('sponsorships.export');
Route::get('/sponsorships/sponsors', [SponsorshipController::class, 'sponsors'])->middleware('throttle:60,1,editorial-sponsorships')->name('sponsorships.sponsors');
Route::get('/sponsorships/events', [SponsorshipController::class, 'events'])->middleware('throttle:60,1,editorial-sponsorships')->name('sponsorships.events');
Route::get('/sponsorships/prefill', [SponsorshipController::class, 'prefill'])->middleware('throttle:60,1,editorial-sponsorships')->name('sponsorships.prefill');
// La stima si chiede a ogni passo e a ogni cambio di periodo o collocazione: un secchio suo, largo.
Route::post('/sponsorships/estimate', [SponsorshipController::class, 'estimate'])->middleware('throttle:120,1,editorial-sponsorships-estimate')->name('sponsorships.estimate');
Route::post('/sponsorships', [SponsorshipController::class, 'store'])->middleware('throttle:20,1,editorial-sponsorships-write')->name('sponsorships.store');
Route::post('/sponsorships/bulk-destroy', [SponsorshipController::class, 'bulkDestroy'])
    ->middleware([RequireRecentPassword::class, 'throttle:10,1,editorial-sponsorships-destroy'])->name('sponsorships.bulk.destroy');

/*
 * Statistiche (R-SP-15): le stesse risposte dell'API di gestione, con tutte
 * le campagne. I nomi seguono quelli della gestione (`*.sponsorships.analytics…`),
 * perché la richiesta che li convalida (`SponsorshipDeskRequest`) li riconosce
 * dal nome.
 */
Route::get('/sponsorships/analytics', [SponsorshipAnalyticsController::class, 'show'])->middleware('throttle:30,1,editorial-sponsorship-analytics')->name('sponsorships.analytics');
Route::get('/sponsorships/analytics/clicks', [SponsorshipAnalyticsController::class, 'clicks'])->middleware('throttle:30,1,editorial-sponsorship-analytics')->name('sponsorships.analytics.clicks');
Route::get('/sponsorships/analytics/campaigns', [SponsorshipAnalyticsController::class, 'campaigns'])->middleware('throttle:30,1,editorial-sponsorship-analytics')->name('sponsorships.analytics.campaigns');
Route::get('/sponsorships/analytics/campaigns/{campaign}', [SponsorshipAnalyticsController::class, 'campaign'])->whereNumber('campaign')
    ->middleware('throttle:30,1,editorial-sponsorship-analytics')->name('sponsorships.analytics.campaign');

Route::prefix('/sponsorships/{sponsorship}')->whereNumber('sponsorship')->group(function (): void {
    Route::get('/', [SponsorshipController::class, 'show'])->middleware('throttle:60,1,editorial-sponsorships')->name('sponsorships.show');
    Route::patch('/', [SponsorshipController::class, 'update'])->middleware('throttle:30,1,editorial-sponsorships-write')->name('sponsorships.update');
    Route::post('/toggle', [SponsorshipController::class, 'toggle'])->middleware('throttle:30,1,editorial-sponsorships-write')->name('sponsorships.toggle');
    Route::delete('/', [SponsorshipController::class, 'destroy'])
        ->middleware([RequireRecentPassword::class, 'throttle:10,1,editorial-sponsorships-destroy'])->name('sponsorships.destroy');
});

/*
 * Ordini (R-SP-07…10): si approvano o si rifiutano, e basta. Il rifiuto di
 * un ordine pagato online rimborsa su Stripe, come nel pannello.
 */
Route::get('/sponsorship-orders', [SponsorshipOrderController::class, 'index'])->middleware('throttle:60,1,editorial-sponsorship-orders')->name('sponsorship-orders.index');
Route::post('/sponsorship-orders/{order}/approve', [SponsorshipOrderController::class, 'approve'])->whereNumber('order')
    ->middleware('throttle:20,1,editorial-sponsorship-orders-decide')->name('sponsorship-orders.approve');
Route::post('/sponsorship-orders/{order}/reject', [SponsorshipOrderController::class, 'reject'])->whereNumber('order')
    ->middleware('throttle:20,1,editorial-sponsorship-orders-decide')->name('sponsorship-orders.reject');

/*
 * Abilitazioni (R-SP-11…12): nessuna eliminazione, come nel pannello.
 */
Route::get('/sponsorship-grants', [SponsorshipGrantController::class, 'index'])->middleware('throttle:60,1,editorial-sponsorship-grants')->name('sponsorship-grants.index');
Route::post('/sponsorship-grants', [SponsorshipGrantController::class, 'store'])->middleware('throttle:20,1,editorial-sponsorship-grants-save')->name('sponsorship-grants.store');
Route::get('/sponsorship-grants/{grant}', [SponsorshipGrantController::class, 'show'])->whereNumber('grant')
    ->middleware('throttle:60,1,editorial-sponsorship-grants')->name('sponsorship-grants.show');
Route::patch('/sponsorship-grants/{grant}', [SponsorshipGrantController::class, 'update'])->whereNumber('grant')
    ->middleware('throttle:30,1,editorial-sponsorship-grants-save')->name('sponsorship-grants.update');

/*
 * Listino (R-SP-13). Eliminare una voce è pericoloso: password recente.
 */
Route::get('/sponsorship-prices', [SponsorshipPriceController::class, 'index'])->middleware('throttle:60,1,editorial-sponsorship-prices')->name('sponsorship-prices.index');
Route::post('/sponsorship-prices', [SponsorshipPriceController::class, 'store'])->middleware('throttle:20,1,editorial-sponsorship-prices-save')->name('sponsorship-prices.store');
Route::patch('/sponsorship-prices/{price}', [SponsorshipPriceController::class, 'update'])->whereNumber('price')
    ->middleware('throttle:30,1,editorial-sponsorship-prices-save')->name('sponsorship-prices.update');
Route::delete('/sponsorship-prices/{price}', [SponsorshipPriceController::class, 'destroy'])->whereNumber('price')
    ->middleware([RequireRecentPassword::class, 'throttle:10,1,editorial-sponsorship-prices-destroy'])->name('sponsorship-prices.destroy');

/*
 * Banner degli sponsor (R-SP-14): i due interruttori.
 */
Route::get('/sponsorship-banners', [SponsorshipBannerController::class, 'show'])->middleware('throttle:60,1,editorial-sponsorship-banners')->name('sponsorship-banners.show');
Route::put('/sponsorship-banners', [SponsorshipBannerController::class, 'update'])->middleware('throttle:20,1,editorial-sponsorship-banners')->name('sponsorship-banners.update');
