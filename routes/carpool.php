<?php

declare(strict_types=1);

use App\Http\Controllers\Carpool\CarpoolController as C;
use App\Http\Controllers\Carpool\CarpoolEvidenceController;
use App\Http\Controllers\Carpool\RideReviewsController;
use App\Http\Middleware\CarpoolPrivacy;
use Illuminate\Support\Facades\Route;

Route::prefix('passaggi')->name('carpool.')->middleware(CarpoolPrivacy::class)->group(function (): void {
    Route::get('/regole', [C::class, 'terms'])->name('terms');
    Route::get('/requisiti', [C::class, 'requirements'])->name('requirements');
    Route::get('/date/{occurrence}', [C::class, 'dates'])->whereNumber('occurrence')->name('dates');
    Route::get('/date/{occurrence}/offri', [C::class, 'create'])->whereNumber('occurrence')->name('create');
    Route::middleware('auth')->group(function (): void {
        Route::get('/conducenti/{driver}/recensioni', [RideReviewsController::class, 'index'])->whereNumber('driver')->name('reviews');
        Route::post('/richieste/{rideRequest}/recensione/{action}', [RideReviewsController::class, 'change'])->whereNumber('rideRequest')->whereIn('action', ['confirm', 'save', 'remove'])->middleware('throttle:carpool-review')->name('review.action');
        Route::get('/', [C::class, 'index'])->name('index');
        Route::get('/riepilogo-avvisi', [C::class, 'summary'])->name('summary');
        Route::get('/offerte/{offer}', [C::class, 'offer'])->whereNumber('offer')->name('offer');
        Route::get('/richieste/{rideRequest}', [C::class, 'rideRequest'])->whereNumber('rideRequest')->name('request');
        Route::post('/azioni/{action}', [C::class, 'action'])->middleware('throttle:carpool-action')->name('action');
        Route::post('/ricerche/{action}', [C::class, 'discovery'])->middleware('throttle:carpool-discovery')->name('discovery');
        Route::get('/messaggi', [C::class, 'chats'])->name('chats');
        Route::get('/messaggi/{chat}', [C::class, 'chat'])->whereNumber('chat')->name('chat');
        Route::post('/messaggi/{chat}/{action}', [C::class, 'chatAction'])->whereNumber('chat')->whereIn('action', ['send', 'preferences'])->middleware('throttle:carpool-chat')->name('chat.action');
        Route::get('/assistenza', [C::class, 'cases'])->name('cases');
        Route::get('/assistenza/{case}', [C::class, 'case'])->whereNumber('case')->name('case');
        Route::post('/segnala', [C::class, 'report'])->middleware('throttle:carpool-report')->name('report');
        Route::post('/assistenza/{case}', [C::class, 'reply'])->whereNumber('case')->middleware('throttle:carpool-case-reply')->name('case.reply');
    });
});

Route::post('/admin-community/cases/{case}/evidence', CarpoolEvidenceController::class)
    ->whereNumber('case')->middleware(['auth', CarpoolPrivacy::class, 'throttle:carpool-evidence'])->name('carpool.evidence');
