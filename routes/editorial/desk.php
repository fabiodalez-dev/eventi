<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Editorial\DeskController;
use App\Http\Controllers\Api\V1\Editorial\UnlockController;
use Illuminate\Support\Facades\Route;

/*
 * Fondamenta della redazione: il banco (chi, dove, con quali permessi), gli
 * elenchi dei moduli e lo sblocco con la password. Fuori dal gruppo di
 * `RequireEditorialUnlock`: servono proprio a sapere se la redazione è
 * sbloccata e a sbloccarla. Il gruppo porta già `auth:sanctum`,
 * `TicketingPrivacy` e `ResolveEditorialDesk`.
 */
Route::get('/desk', [DeskController::class, 'show'])->middleware('throttle:60,1,editorial-desk')->name('desk');
Route::get('/options', [DeskController::class, 'options'])->middleware('throttle:60,1,editorial-options')->name('options');
// Prova password: 6 tentativi ogni 15 minuti per persona.
Route::post('/unlock', UnlockController::class)->middleware('throttle:6,15,editorial-unlock')->name('unlock');
