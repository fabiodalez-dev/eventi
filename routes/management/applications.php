<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Management\ApplicationController;
use App\Http\Middleware\RequireConfirmedAccount;
use Illuminate\Support\Facades\Route;

/*
 * Registrare o rivendicare (P13, W-AP-01…06). Montato da `routes/api.php`
 * dentro il gruppo `v1` (nomi `api.v1.…`, città da `ResolveApiCity`), fuori
 * dalle letture in cache: rispondono per la persona collegata, non per un
 * locale che già gestisce, quindi non passano da `ResolveManagementSubject`.
 *
 * Si scrive solo da collegati con l'email confermata: niente account creato
 * dal modulo, niente Turnstile. «Le mie richieste» è la rotta di prima,
 * `GET /me/venue-applications`.
 *
 * Le tre letture del modulo (gli elenchi, «è già a catalogo?», il locale da
 * rivendicare) sono pubbliche come le pagine del sito che le mostrano
 * (`/registra-il-tuo-locale?cerca=`, `/locali/{locale}/rivendica`): l'ospite
 * compila il modulo nell'app, crea l'account con `POST /auth/register` e la
 * richiesta parte da qui quando arriva la sessione (Q2, W-AP-02). Stessi
 * secchi di prima.
 *
 * I limiti di frequenza hanno le chiavi dei moduli del sito
 * (`routes/public.php`): sito e app consumano lo stesso secchio.
 */
Route::get('/venue-applications/lookup', [ApplicationController::class, 'lookup'])
    ->middleware('throttle:60,1,venue-catalogue-search')->name('venue-applications.lookup');
Route::get('/me/venue-applications/options', [ApplicationController::class, 'options'])
    ->middleware('throttle:60,1,venue-application-options')->name('me.venue-applications.options');
Route::get('/venues/{slug}/claim', [ApplicationController::class, 'claimTarget'])
    ->middleware('throttle:60,1,venue-claim-target')->name('venues.claim.show');

Route::middleware(['auth:sanctum', RequireConfirmedAccount::class])->group(function (): void {
    Route::post('/me/venue-applications', [ApplicationController::class, 'storeVenue'])
        ->middleware('throttle:public-forms')->name('me.venue-applications.store');
    Route::post('/me/venue-applications/{application}/confirm', [ApplicationController::class, 'confirm'])->whereNumber('application')
        ->middleware('throttle:10,60,venue-application-confirm')->name('me.venue-applications.confirm');
    Route::delete('/me/venue-applications/{application}', [ApplicationController::class, 'discard'])->whereNumber('application')
        ->middleware('throttle:10,60,venue-application-confirm')->name('me.venue-applications.discard');
    Route::post('/me/organizer-applications', [ApplicationController::class, 'storeOrganizer'])
        ->middleware('throttle:10,60,organizer-application')->name('me.organizer-applications.store');
    Route::post('/venues/{slug}/claim', [ApplicationController::class, 'claim'])
        ->middleware('throttle:10,60,venue-claim')->name('venues.claim');
});
