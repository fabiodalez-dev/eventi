<?php

declare(strict_types=1);

use App\Http\Controllers\Community\CommunityController;
use App\Http\Controllers\Community\WhatsappController;
use App\Http\Middleware\Api\ResolveApiCity;
use App\Http\Middleware\CommunityPrivacy;
use Illuminate\Support\Facades\Route;

// Stessi nomi di contatore di routes/community.php: sito e app consumano lo stesso limite.
Route::prefix('v1/community')->middleware([CommunityPrivacy::class, ResolveApiCity::class])->group(function (): void {
    Route::get('/avatar/{profile}', [CommunityController::class, 'avatar'])->whereNumber('profile')->name('community.api.avatar');
    Route::get('/people', [CommunityController::class, 'people']);
    Route::get('/people/{handle}', [CommunityController::class, 'profile'])->where('handle', '[a-z0-9_]+');
    Route::get('/posts/{post}', [CommunityController::class, 'post'])->whereNumber('post');
    Route::middleware(['auth:sanctum', 'verified'])->group(function (): void {
        Route::get('/feed', [CommunityController::class, 'feed']);
        Route::get('/profile', [CommunityController::class, 'settings']);
        Route::post('/profile', [CommunityController::class, 'update'])->middleware('throttle:10,1,community-profile');
        Route::get('/whatsapp', [WhatsappController::class, 'show']);
        Route::post('/whatsapp', [WhatsappController::class, 'send'])->middleware('throttle:5,60,community-wa-send');
        Route::post('/whatsapp/confirm', [WhatsappController::class, 'confirm'])->middleware('throttle:15,10,community-wa-confirm');
        Route::post('/whatsapp/skip', [WhatsappController::class, 'skip']);
        Route::delete('/whatsapp', [WhatsappController::class, 'revoke'])->middleware('throttle:5,60,community-wa-revoke');
        Route::get('/followers', [CommunityController::class, 'followers']);
        Route::post('/people/{user}/follow', [CommunityController::class, 'follow'])->whereNumber('user')->middleware('throttle:20,1,community-follow');
        Route::delete('/people/{user}/follow', [CommunityController::class, 'follow'])->whereNumber('user')->middleware('throttle:20,1,community-follow');
        Route::post('/people/{user}/block', [CommunityController::class, 'block'])->whereNumber('user')->middleware('throttle:20,1,community-block');
        Route::delete('/people/{user}/block', [CommunityController::class, 'block'])->whereNumber('user')->middleware('throttle:20,1,community-block');
        Route::get('/saved/{occurrence}', [CommunityController::class, 'compose'])->whereNumber('occurrence');
        Route::put('/saved/{occurrence}', [CommunityController::class, 'publish'])->whereNumber('occurrence')->middleware('throttle:20,1,community-publish');
        Route::post('/posts/{post}/comments', [CommunityController::class, 'comment'])->whereNumber('post')->middleware('throttle:10,1,community-comment');
        Route::delete('/comments/{comment}', [CommunityController::class, 'deleteComment'])->whereNumber('comment')->middleware('throttle:20,1,community-comment-delete');
        Route::post('/reports', [CommunityController::class, 'report'])->middleware('throttle:5,60,community-report');
    });
});
