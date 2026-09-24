<?php

declare(strict_types=1);

use App\Http\Controllers\Community\CommunityController;
use App\Http\Controllers\Community\InboxController;
use App\Http\Controllers\Community\PollController;
use App\Http\Controllers\Community\WhatsappController;
use App\Http\Middleware\CommunityPrivacy;
use Illuminate\Support\Facades\Route;

// Ogni azione ha il proprio contatore, condiviso fra sito e API: senza il terzo argomento
// le rotte con gli stessi limiti finiscono nello stesso secchio e seguire cinque persone
// esaurirebbe gli invii WhatsApp dell'ora.
Route::middleware(CommunityPrivacy::class)->group(function (): void {
    Route::get('/community-avatar/{profile}', [CommunityController::class, 'avatar'])->whereNumber('profile')->name('community.avatar');
    Route::get('/persone', [CommunityController::class, 'people'])->name('community.people');
    Route::get('/persone/{handle}', [CommunityController::class, 'profile'])->where('handle', '[a-z0-9_]+')->name('community.profile');
    Route::get('/bacheca/post/{post}', [CommunityController::class, 'post'])->whereNumber('post')->name('community.post');
    Route::middleware(['auth', 'verified'])->group(function (): void {
        Route::get('/bacheca', [CommunityController::class, 'feed'])->name('community.feed');
        Route::get('/profilo-social', [CommunityController::class, 'settings'])->name('community.settings');
        Route::post('/profilo-social', [CommunityController::class, 'update'])->middleware('throttle:10,1,community-profile')->name('community.settings.update');
        Route::get('/verifica-whatsapp', [WhatsappController::class, 'show'])->name('community.whatsapp');
        Route::post('/verifica-whatsapp', [WhatsappController::class, 'send'])->middleware('throttle:5,60,community-wa-send')->name('community.whatsapp.send');
        Route::post('/verifica-whatsapp/conferma', [WhatsappController::class, 'confirm'])->middleware('throttle:15,10,community-wa-confirm')->name('community.whatsapp.confirm');
        Route::post('/verifica-whatsapp/dopo', [WhatsappController::class, 'skip'])->name('community.whatsapp.skip');
        Route::delete('/verifica-whatsapp', [WhatsappController::class, 'revoke'])->middleware('throttle:5,60,community-wa-revoke')->name('community.whatsapp.revoke');
        Route::get('/persone-che-mi-seguono', [CommunityController::class, 'followers'])->name('community.followers');
        Route::post('/persone/{user}/segui', [CommunityController::class, 'follow'])->whereNumber('user')->middleware('throttle:20,1,community-follow')->name('community.follow');
        Route::delete('/persone/{user}/segui', [CommunityController::class, 'follow'])->whereNumber('user')->middleware('throttle:20,1,community-follow')->name('community.unfollow');
        Route::post('/persone/{user}/blocca', [CommunityController::class, 'block'])->whereNumber('user')->middleware('throttle:20,1,community-block')->name('community.block');
        Route::delete('/persone/{user}/blocca', [CommunityController::class, 'block'])->whereNumber('user')->middleware('throttle:20,1,community-block')->name('community.unblock');
        Route::get('/salvataggi/{occurrence}/visibilita', [CommunityController::class, 'compose'])->whereNumber('occurrence')->name('community.compose');
        Route::put('/salvataggi/{occurrence}/visibilita', [CommunityController::class, 'publish'])->whereNumber('occurrence')->middleware('throttle:20,1,community-publish')->name('community.publish');
        Route::post('/salvataggi/{occurrence}/partecipazione', [CommunityController::class, 'attendance'])->whereNumber('occurrence')->middleware('throttle:30,1,community-attendance')->name('community.attendance');
        Route::post('/bacheca/post/{post}/commenti', [CommunityController::class, 'comment'])->whereNumber('post')->middleware('throttle:10,1,community-comment')->name('community.comment');
        Route::delete('/bacheca/commenti/{comment}', [CommunityController::class, 'deleteComment'])->whereNumber('comment')->middleware('throttle:20,1,community-comment-delete')->name('community.comment.delete');
        Route::post('/bacheca/segnala', [CommunityController::class, 'report'])->middleware('throttle:5,60,community-report')->name('community.report');
        /*
         * I sondaggi vivono di link: nessun elenco, nessuna pagina che li
         * raccolga. Il codice nell'indirizzo è l'unica chiave, e per questo la
         * rotta di voto passa comunque dall'accesso.
         */
        Route::post('/sondaggi', [PollController::class, 'store'])->middleware('throttle:10,60,polls-create')->name('polls.store');
        Route::get('/sondaggi/{poll:token}', [PollController::class, 'show'])->name('polls.show');
        Route::post('/sondaggi/{poll:token}/voti/{option}', [PollController::class, 'vote'])->whereNumber('option')->middleware('throttle:60,1,polls-vote')->name('polls.vote');
        Route::post('/sondaggi/{poll:token}/chiusura', [PollController::class, 'close'])->name('polls.close');
        Route::post('/sondaggi/{poll:token}/uscita', [PollController::class, 'leave'])->name('polls.leave');

        Route::get('/avvisi', [InboxController::class, 'index'])->name('community.inbox');
        Route::get('/avvisi/ultimi', [InboxController::class, 'latest'])->middleware('throttle:60,1,community-inbox-latest')->name('community.inbox.latest');
        Route::post('/avvisi/letti', [InboxController::class, 'readAll'])->name('community.inbox.read');
    });
});
