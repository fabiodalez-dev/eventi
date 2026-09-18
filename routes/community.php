<?php

declare(strict_types=1);

use App\Http\Controllers\Community\CommunityController;
use App\Http\Controllers\Community\InboxController;
use App\Http\Controllers\Community\WhatsappController;
use App\Http\Middleware\CommunityPrivacy;
use Illuminate\Support\Facades\Route;

Route::middleware(CommunityPrivacy::class)->group(function (): void {
    Route::get('/community-avatar/{profile}', [CommunityController::class, 'avatar'])->whereNumber('profile')->name('community.avatar');
    Route::get('/persone', [CommunityController::class, 'people'])->name('community.people');
    Route::get('/persone/{handle}', [CommunityController::class, 'profile'])->where('handle', '[a-z0-9_]+')->name('community.profile');
    Route::get('/bacheca/post/{post}', [CommunityController::class, 'post'])->whereNumber('post')->name('community.post');
    Route::middleware(['auth', 'verified'])->group(function (): void {
        Route::get('/bacheca', [CommunityController::class, 'feed'])->name('community.feed');
        Route::get('/profilo-social', [CommunityController::class, 'settings'])->name('community.settings');
        Route::post('/profilo-social', [CommunityController::class, 'update'])->middleware('throttle:10,1')->name('community.settings.update');
        Route::get('/verifica-whatsapp', [WhatsappController::class, 'show'])->name('community.whatsapp');
        Route::post('/verifica-whatsapp', [WhatsappController::class, 'send'])->middleware('throttle:5,60')->name('community.whatsapp.send');
        Route::post('/verifica-whatsapp/conferma', [WhatsappController::class, 'confirm'])->middleware('throttle:15,10')->name('community.whatsapp.confirm');
        Route::post('/verifica-whatsapp/dopo', [WhatsappController::class, 'skip'])->name('community.whatsapp.skip');
        Route::delete('/verifica-whatsapp', [WhatsappController::class, 'revoke'])->middleware('throttle:5,60')->name('community.whatsapp.revoke');
        Route::get('/persone-che-mi-seguono', [CommunityController::class, 'followers'])->name('community.followers');
        Route::post('/persone/{user}/segui', [CommunityController::class, 'follow'])->whereNumber('user')->middleware('throttle:20,1')->name('community.follow');
        Route::delete('/persone/{user}/segui', [CommunityController::class, 'follow'])->whereNumber('user')->middleware('throttle:20,1')->name('community.unfollow');
        Route::post('/persone/{user}/blocca', [CommunityController::class, 'block'])->whereNumber('user')->middleware('throttle:20,1')->name('community.block');
        Route::delete('/persone/{user}/blocca', [CommunityController::class, 'block'])->whereNumber('user')->middleware('throttle:20,1')->name('community.unblock');
        Route::get('/salvataggi/{occurrence}/visibilita', [CommunityController::class, 'compose'])->whereNumber('occurrence')->name('community.compose');
        Route::put('/salvataggi/{occurrence}/visibilita', [CommunityController::class, 'publish'])->whereNumber('occurrence')->middleware('throttle:20,1')->name('community.publish');
        Route::post('/bacheca/post/{post}/commenti', [CommunityController::class, 'comment'])->whereNumber('post')->middleware('throttle:10,1')->name('community.comment');
        Route::delete('/bacheca/commenti/{comment}', [CommunityController::class, 'deleteComment'])->whereNumber('comment')->middleware('throttle:20,1')->name('community.comment.delete');
        Route::post('/bacheca/segnala', [CommunityController::class, 'report'])->middleware('throttle:5,60')->name('community.report');
        Route::get('/avvisi', [InboxController::class, 'index'])->name('community.inbox');
        Route::post('/avvisi/letti', [InboxController::class, 'readAll'])->name('community.inbox.read');
    });
});
