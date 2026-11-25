<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Management\CommentModerationController;
use App\Http\Controllers\Api\V1\Management\ReceivedReviewController;
use Illuminate\Support\Facades\Route;

/*
 * Commenti e recensioni (P7). Incluso due volte da `routes/api.php`, dentro
 * `venues/{venue}` e dentro `organizers/{organizer}`: le rotte sono le stesse
 * per i due soggetti, come le due pagine «Commenti agli eventi» e
 * «Recensioni ricevute» dei pannelli. Il gruppo porta già `auth:sanctum`,
 * `TicketingPrivacy` e `ResolveManagementSubject`.
 *
 * I pannelli non hanno limiti di invio su queste azioni; qui un secchio per
 * persona (`management-community`) tiene a bada un client che impazzisce.
 * La segnalazione usa lo stesso secchio della scheda pubblica
 * (`catalog-review-report`): dall'app e dal sito si consuma lo stesso.
 *
 * @var string $subjectType
 */
Route::get('/comments', [CommentModerationController::class, 'index'])->name('comments.index');
Route::post('/comments/{comment}/hide', [CommentModerationController::class, 'hide'])->whereNumber('comment')
    ->middleware('throttle:60,1,management-community')->name('comments.hide');
Route::post('/comments/{comment}/restore', [CommentModerationController::class, 'restore'])->whereNumber('comment')
    ->middleware('throttle:60,1,management-community')->name('comments.restore');

Route::get('/reviews', [ReceivedReviewController::class, 'index'])->name('reviews.index');
Route::put('/reviews/{review}/reply', [ReceivedReviewController::class, 'reply'])->whereNumber('review')
    ->middleware('throttle:60,1,management-community')->name('reviews.reply.update');
Route::delete('/reviews/{review}/reply', [ReceivedReviewController::class, 'removeReply'])->whereNumber('review')
    ->middleware('throttle:60,1,management-community')->name('reviews.reply.destroy');
Route::post('/reviews/{review}/report', [ReceivedReviewController::class, 'report'])->whereNumber('review')
    ->middleware('throttle:10,60,catalog-review-report')->name('reviews.report');
