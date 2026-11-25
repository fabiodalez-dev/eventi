<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Management\DashboardController;
use Illuminate\Support\Facades\Route;

/*
 * Riepilogo (P11): la prima pagina dei pannelli — avviso della mappa,
 * cartellone, oggi, prossimi eventi, ultime visite — e il programma della
 * settimana in PDF del locale.
 *
 * Incluso due volte da `routes/api.php`, dentro `venues/{venue}` e dentro
 * `organizers/{organizer}`: `$subjectType` (`venue` o `organizer`) dice in
 * quale dei due. Il gruppo porta già `auth:sanctum`, `TicketingPrivacy` e
 * `ResolveManagementSubject`. Il programma esiste solo per il locale, e
 * consuma lo stesso secchio della pagina pubblica (`venue-programme`).
 *
 * @var string $subjectType
 */
Route::get('/dashboard', [DashboardController::class, 'show'])
    ->middleware('throttle:60,1,management-dashboard')->name('dashboard');

if ($subjectType === 'venue') {
    Route::get('/dashboard/programme', [DashboardController::class, 'programme'])
        ->middleware('throttle:30,1,venue-programme')->name('dashboard.programme');
}
