<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Management\OptionsController;
use App\Http\Controllers\Api\V1\Management\SubjectController;
use Illuminate\Support\Facades\Route;

/*
 * Identità di gestione (fondamenta): chi gestisce che cosa, e gli elenchi dei
 * moduli. Fuori dai sottogruppi del soggetto, quindi senza
 * `ResolveManagementSubject`: rispondono per la persona, non per un locale.
 */
Route::get('/subjects', [SubjectController::class, 'index'])->name('subjects');
Route::get('/options', OptionsController::class)->name('options');
