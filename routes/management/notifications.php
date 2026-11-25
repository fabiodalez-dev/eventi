<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Management\ManagementNoticeController;
use Illuminate\Support\Facades\Route;

/*
 * Notifiche e chiusura del giro (P12): gli avvisi a chi gestisce questo
 * soggetto e la traduzione dello slug di un evento del pannello nel suo id.
 *
 * Incluso due volte da `routes/api.php`, dentro `venues/{venue}` e dentro
 * `organizers/{organizer}`: `$subjectType` (`venue` o `organizer`) dice in
 * quale dei due, per le rotte di un solo soggetto. Il gruppo porta già
 * `auth:sanctum`, `TicketingPrivacy` e `ResolveManagementSubject`.
 *
 * @var string $subjectType
 */
Route::get('/notifications', [ManagementNoticeController::class, 'index'])->name('notifications.index');
Route::get('/events/by-slug/{slug}', [ManagementNoticeController::class, 'eventBySlug'])
    ->where('slug', '[a-z0-9][a-z0-9-]{0,190}')
    ->name('events.by-slug');
