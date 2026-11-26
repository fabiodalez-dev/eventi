<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Editorial\CarpoolController;
use App\Http\Controllers\Api\V1\Editorial\CommunityController;
use App\Http\Middleware\RequireRecentPassword;
use App\Services\Editorial\CarpoolDesk;
use App\Services\Editorial\CommunityDesk;
use Illuminate\Support\Facades\Route;

/*
 * R7 — Community e passaggi (R-CM-01…05, R-CP-01…12).
 *
 * Incluso da `routes/api.php` dentro `v1/editorial` (nome `api.v1.editorial.`),
 * nel gruppo che porta già `auth:sanctum`, `TicketingPrivacy`,
 * `ResolveEditorialDesk` e `RequireEditorialUnlock`. Percorsi di questo
 * pacchetto: community, carpool.
 *
 * Ogni rotta ha un nome (`<area>.<azione>`) e un `throttle:N,M,<nome>`; le
 * azioni pericolose (decisione 4) aggiungono
 * `->middleware(RequireRecentPassword::class)`. Le regole sono nel contratto
 * delle fondamenta e le controlla `EditorialRoutesTest`.
 *
 * Pericolose (password recente): le sospensioni (`community.users.suspend`,
 * `carpool.users.suspend`), la conservazione delle evidenze di un caso
 * (`carpool.cases.hold`, nel pannello chiede la password) e la loro lettura
 * (`carpool.cases.evidence`, idem). Togliere una restrizione su una serata
 * non elimina un'entità: `remove`.
 *
 * Solo sul sito (decisione 8): l'esportazione delle evidenze di un caso
 * (R-CP-11, `carpool.evidence` con `export`).
 */

// Profili e post (R-CM-01…05): `community.manage`
Route::get('/community/{section}', [CommunityController::class, 'index'])->whereIn('section', CommunityDesk::SECTIONS)
    ->middleware('throttle:60,1,editorial-community')->name('community.index');
Route::post('/community/profiles/{profile}/feature', [CommunityController::class, 'feature'])->whereNumber('profile')
    ->middleware('throttle:30,1,editorial-community-feature')->name('community.profiles.feature');
Route::post('/community/users/{user}/suspend', [CommunityController::class, 'suspend'])->whereNumber('user')
    ->middleware([RequireRecentPassword::class, 'throttle:20,1,editorial-community-suspend'])->name('community.users.suspend');
Route::post('/community/users/{user}/unsuspend', [CommunityController::class, 'unsuspend'])->whereNumber('user')
    ->middleware('throttle:20,1,editorial-community-unsuspend')->name('community.users.unsuspend');
Route::post('/community/{kind}/{id}/hide', [CommunityController::class, 'hide'])->whereIn('kind', ['posts', 'comments'])->whereNumber('id')
    ->middleware('throttle:30,1,editorial-community-hide')->name('community.content.hide');
Route::post('/community/{kind}/{id}/restore', [CommunityController::class, 'restore'])->whereIn('kind', ['posts', 'comments'])->whereNumber('id')
    ->middleware('throttle:30,1,editorial-community-restore')->name('community.content.restore');
Route::delete('/community/restrictions/{restriction}', [CommunityController::class, 'removeRestriction'])->whereNumber('restriction')
    ->middleware('throttle:30,1,editorial-community-restrictions-remove')->name('community.restrictions.remove');

// Passaggi in auto (R-CP-01…10, 12): `community_cases.manage`, più `carpool.manage` dove il pannello lo chiede
Route::get('/carpool/cases/{case}', [CarpoolController::class, 'show'])->whereNumber('case')
    ->middleware('throttle:60,1,editorial-carpool-cases-show')->name('carpool.cases.show');
Route::get('/carpool/{section}', [CarpoolController::class, 'index'])->whereIn('section', CarpoolDesk::SECTIONS)
    ->middleware('throttle:60,1,editorial-carpool')->name('carpool.index');
Route::post('/carpool/cases/{case}/manage', [CarpoolController::class, 'manage'])->whereNumber('case')
    ->middleware('throttle:30,1,editorial-carpool-cases-manage')->name('carpool.cases.manage');
Route::post('/carpool/cases/{case}/reply', [CarpoolController::class, 'reply'])->whereNumber('case')
    ->middleware('throttle:20,1,editorial-carpool-cases-reply')->name('carpool.cases.reply');
Route::post('/carpool/cases/{case}/hold', [CarpoolController::class, 'hold'])->whereNumber('case')
    ->middleware([RequireRecentPassword::class, 'throttle:10,1,editorial-carpool-cases-hold'])->name('carpool.cases.hold');
Route::post('/carpool/cases/{case}/freeze', [CarpoolController::class, 'freeze'])->whereNumber('case')
    ->middleware('throttle:20,1,editorial-carpool-cases-freeze')->name('carpool.cases.freeze');
// La lettura delle evidenze consuma anche il secchio del sito (`carpool-evidence`, 20 l'ora per persona).
Route::post('/carpool/cases/{case}/evidence', [CarpoolController::class, 'evidence'])->whereNumber('case')
    ->middleware([RequireRecentPassword::class, 'throttle:carpool-evidence', 'throttle:20,60,editorial-carpool-cases-evidence'])->name('carpool.cases.evidence');
Route::post('/carpool/rides/{offer}/cancel', [CarpoolController::class, 'cancelRide'])->whereNumber('offer')
    ->middleware('throttle:20,1,editorial-carpool-rides-cancel')->name('carpool.rides.cancel');
Route::post('/carpool/reviews/{review}/moderate', [CarpoolController::class, 'moderateReview'])->whereNumber('review')
    ->middleware('throttle:30,1,editorial-carpool-reviews-moderate')->name('carpool.reviews.moderate');
Route::post('/carpool/users/{user}/suspend', [CarpoolController::class, 'suspendUser'])->whereNumber('user')
    ->middleware([RequireRecentPassword::class, 'throttle:20,1,editorial-carpool-users-suspend'])->name('carpool.users.suspend');
Route::post('/carpool/users/{user}/restore', [CarpoolController::class, 'restoreUser'])->whereNumber('user')
    ->middleware('throttle:20,1,editorial-carpool-users-restore')->name('carpool.users.restore');
Route::post('/carpool/deliveries/{delivery}/retry', [CarpoolController::class, 'retryDelivery'])->whereNumber('delivery')
    ->middleware('throttle:20,1,editorial-carpool-deliveries-retry')->name('carpool.deliveries.retry');
