<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Management\SponsorshipAnalyticsController;
use App\Http\Controllers\Api\V1\Management\SponsorshipController;
use Illuminate\Support\Facades\Route;

/*
 * Sponsorizzazioni (P6): le pagine «Sponsorizzazioni» e «Statistiche
 * sponsorizzazioni» dei due pannelli.
 *
 * Incluso due volte da `routes/api.php`, dentro `venues/{venue}` e dentro
 * `organizers/{organizer}`: le rotte sono le stesse per i due soggetti. Il
 * gruppo porta già `auth:sanctum`, `TicketingPrivacy` e
 * `ResolveManagementSubject`.
 *
 * Gli id della rotta (`order`, `grant`, `campaign`) si cercano solo fra le
 * righe del soggetto (`SponsorshipDesk`, `SponsorshipReport`): un id di un
 * altro committente risponde 404. L'invio non ha un throttle di rotta
 * stretto: il tetto vero (cinque invii l'ora per persona) è quello della
 * pagina del pannello, con la stessa chiave, e risponde 429.
 *
 * @var string $subjectType
 */
Route::prefix('sponsorships')->name('sponsorships.')->group(function (): void {
    Route::get('/', [SponsorshipController::class, 'index'])->middleware('throttle:60,1,management-sponsorships')->name('index');
    Route::get('/events', [SponsorshipController::class, 'events'])->middleware('throttle:60,1,management-sponsorships')->name('events');
    Route::get('/catalog', [SponsorshipController::class, 'catalog'])->middleware('throttle:60,1,management-sponsorships')->name('catalog');
    Route::get('/campaigns', [SponsorshipController::class, 'campaigns'])->middleware('throttle:60,1,management-sponsorships')->name('campaigns.index');
    Route::get('/campaigns/{campaign}', [SponsorshipAnalyticsController::class, 'campaign'])->whereNumber('campaign')
        ->middleware('throttle:60,1,management-sponsorships')->name('campaigns.show');

    Route::post('/orders', [SponsorshipController::class, 'store'])->middleware('throttle:20,1,management-sponsorship-writes')->name('orders.store');
    Route::post('/orders/{order}/retry', [SponsorshipController::class, 'retry'])->whereNumber('order')
        ->middleware('throttle:20,1,management-sponsorship-writes')->name('orders.retry');
    Route::post('/orders/{order}/cancel', [SponsorshipController::class, 'cancel'])->whereNumber('order')
        ->middleware('throttle:20,1,management-sponsorship-writes')->name('orders.cancel');
    Route::post('/orders/{order}/reconcile', [SponsorshipController::class, 'reconcile'])->whereNumber('order')
        ->middleware('throttle:30,1,management-sponsorship-writes')->name('orders.reconcile');
    Route::delete('/orders/{order}', [SponsorshipController::class, 'withdraw'])->whereNumber('order')
        ->middleware('throttle:20,1,management-sponsorship-writes')->name('orders.withdraw');

    Route::post('/grants/{grant}/events', [SponsorshipController::class, 'activate'])->whereNumber('grant')
        ->middleware('throttle:30,1,management-sponsorship-writes')->name('grants.events');
    Route::post('/campaigns/{campaign}/stop', [SponsorshipController::class, 'stop'])->whereNumber('campaign')
        ->middleware('throttle:30,1,management-sponsorship-writes')->name('campaigns.stop');

    /* Le statistiche leggono molte righe: un throttle a parte, più stretto. */
    Route::get('/analytics', [SponsorshipAnalyticsController::class, 'show'])->middleware('throttle:30,1,management-sponsorship-analytics')->name('analytics');
    Route::get('/analytics/clicks', [SponsorshipAnalyticsController::class, 'clicks'])->middleware('throttle:30,1,management-sponsorship-analytics')->name('analytics.clicks');
    Route::get('/analytics/campaigns', [SponsorshipAnalyticsController::class, 'campaigns'])->middleware('throttle:30,1,management-sponsorship-analytics')->name('analytics.campaigns');
});
