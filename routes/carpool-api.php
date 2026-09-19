<?php

declare(strict_types=1);

use App\Http\Controllers\Carpool\CarpoolController as C;
use App\Http\Controllers\Carpool\RideReviewsController;
use App\Http\Middleware\CarpoolPrivacy;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/carpool')->middleware(['auth:sanctum', CarpoolPrivacy::class])->group(function (): void {
    Route::get('/drivers/{driver}/reviews', [RideReviewsController::class, 'index'])->whereNumber('driver');
    Route::post('/requests/{rideRequest}/review/{action}', [RideReviewsController::class, 'change'])->whereNumber('rideRequest')->whereIn('action', ['confirm', 'save', 'remove'])->middleware('throttle:carpool-review');
    Route::get('/terms', [C::class, 'terms']);
    Route::get('/requirements', [C::class, 'requirements']);
    Route::get('/occurrences/{occurrence}', [C::class, 'dates'])->whereNumber('occurrence');
    Route::get('/occurrences/{occurrence}/offer', [C::class, 'create'])->whereNumber('occurrence');
    Route::get('/me', [C::class, 'index']);
    Route::get('/summary', [C::class, 'summary']);
    Route::get('/offers/{offer}', [C::class, 'offer'])->whereNumber('offer');
    Route::get('/requests/{rideRequest}', [C::class, 'rideRequest'])->whereNumber('rideRequest');
    Route::post('/actions/{action}', [C::class, 'action'])->middleware('throttle:carpool-action');
    Route::post('/discovery/{action}', [C::class, 'discovery'])->middleware('throttle:carpool-discovery');
    Route::get('/chats', [C::class, 'chats']);
    Route::get('/chats/{chat}', [C::class, 'chat'])->whereNumber('chat');
    Route::post('/chats/{chat}/{action}', [C::class, 'chatAction'])->whereNumber('chat')->whereIn('action', ['send', 'preferences'])->middleware('throttle:carpool-chat');
    Route::get('/cases', [C::class, 'cases']);
    Route::get('/cases/{case}', [C::class, 'case'])->whereNumber('case');
    Route::post('/reports', [C::class, 'report'])->middleware('throttle:carpool-report');
    Route::post('/cases/{case}', [C::class, 'reply'])->whereNumber('case')->middleware('throttle:carpool-case-reply');
});
