<?php

declare(strict_types=1);

use App\Enums\InstallerStep;
use App\Http\Controllers\Installer\InstallerController;
use App\Http\Middleware\InstallerGate;
use App\Http\Middleware\InstallerStepOrder;
use Illuminate\Support\Facades\Route;

/*
 * Il wizard di installazione (D42).
 *
 * Questo file è incluso da `routes/web.php` **prima** del gruppo `/{city}`:
 * il segmento `installazione` corrisponde al modello `[a-z][a-z0-9-]*` di
 * quel gruppo, quindi registrandolo dopo verrebbe letto come una città
 * inesistente e risponderebbe 404 (è la trappola già documentata in D33).
 *
 * Ogni rotta si chiama `installer.<valore del passo>`, che è ciò che permette
 * a `InstallerStep::url()` di costruire l'indirizzo senza una seconda tabella
 * di corrispondenze da tenere allineata a mano.
 *
 * `InstallerGate` decide se rispondere: marcatore, diagnosi del database rotto
 * e auto-marcatura di un'installazione preesistente stanno tutti lì.
 */
Route::middleware([InstallerGate::class, InstallerStepOrder::class])
    ->prefix('installazione')
    ->group(function (): void {
        Route::get('/', [InstallerController::class, 'index'])->name('installer.index');

        Route::get(InstallerStep::Requirements->value, [InstallerController::class, 'requirements'])
            ->name('installer.'.InstallerStep::Requirements->value);
        Route::post(InstallerStep::Requirements->value, [InstallerController::class, 'storeRequirements']);

        /*
         * Il limite di frequenza sta qui e non altrove: è l'unico endpoint del
         * wizard che apre una connessione verso un host scelto da chi chiama,
         * senza autenticazione. Dieci tentativi al minuto bastano a chi sta
         * copiando le credenziali dal pannello dell'hosting e non bastano a chi
         * sta usando il sito come scanner di porte altrui.
         */
        Route::get(InstallerStep::Database->value, [InstallerController::class, 'database'])
            ->name('installer.'.InstallerStep::Database->value);
        Route::post(InstallerStep::Database->value, [InstallerController::class, 'storeDatabase'])
            ->middleware('throttle:10,1');

        Route::get(InstallerStep::Application->value, [InstallerController::class, 'application'])
            ->name('installer.'.InstallerStep::Application->value);
        Route::post(InstallerStep::Application->value, [InstallerController::class, 'storeApplication']);

        Route::get(InstallerStep::City->value, [InstallerController::class, 'city'])
            ->name('installer.'.InstallerStep::City->value);
        Route::post(InstallerStep::City->value, [InstallerController::class, 'storeCity']);

        Route::get(InstallerStep::Admin->value, [InstallerController::class, 'admin'])
            ->name('installer.'.InstallerStep::Admin->value);
        Route::post(InstallerStep::Admin->value, [InstallerController::class, 'storeAdmin']);

        Route::get(InstallerStep::Run->value, [InstallerController::class, 'run'])
            ->name('installer.'.InstallerStep::Run->value);
        Route::post(InstallerStep::Run->value, [InstallerController::class, 'storeRun']);

        Route::get(InstallerStep::Done->value, [InstallerController::class, 'done'])
            ->name('installer.'.InstallerStep::Done->value);
    });
