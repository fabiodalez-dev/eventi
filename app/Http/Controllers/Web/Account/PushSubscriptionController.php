<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\Enums\DevicePlatform;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Account\StorePushSubscriptionRequest;
use App\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Il browser si iscrive e si disiscrive dalle notifiche push (§15.6, D54).
 *
 * **Perché non si riusa `POST /v1/me/devices`**, che fa esattamente questo.
 * Quella rotta sta dietro `auth:sanctum` e `bootstrap/app.php` non chiama
 * `statefulApi()`: sul gruppo `api` non c'è sessione, quindi Sanctum
 * interroga il guard `web`, non trova nessuno e risponde 401. Una pagina del
 * sito, che una sessione ce l'ha, non può usarla. La scelta è deliberata —
 * l'API è per le app, che portano un token — e la conseguenza è questa
 * seconda porta, non il rilassamento della prima.
 *
 * **Perché serve la sessione e non basta il collegamento firmato** con cui si
 * raggiunge la pagina delle preferenze (§15.9). Quel collegamento arriva per
 * email e viaggia: oggi permette solo di **ridurre** ciò che si riceve, e
 * questo lo rende innocuo se qualcuno lo inoltra. Iscrivere un browser è il
 * gesto opposto — dirotta il contenuto delle notifiche future verso uno
 * schermo — e un collegamento inoltrato non deve poterlo fare.
 *
 * Risponde JSON perché a chiamare è il codice del browser, non un modulo.
 */
final class PushSubscriptionController extends Controller
{
    public function store(StorePushSubscriptionRequest $request): JsonResponse
    {
        $user = Auth::user();

        if ($user === null) {
            return response()->json(['message' => __('notifications.push.unauthenticated')], 401);
        }

        $endpoint = $request->endpoint();

        /*
         * Lo stesso browser può aver ospitato due persone: l'endpoint è unico
         * per installazione, non per account. Se resta appeso al precedente,
         * quello continua a ricevere sullo schermo di chi si è collegato
         * dopo. Si revoca invece di cancellare, come ovunque qui.
         */
        Device::query()
            ->where('endpoint', $endpoint)
            ->where('user_id', '!=', $user->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => CarbonImmutable::now()]);

        /*
         * La seconda iscrizione dello stesso browser aggiorna la riga invece
         * di crearne un'altra, come `DeviceController`: §3.14 di `SCHEMA.md`
         * rinuncia di proposito a un indice unico su una colonna da 512
         * caratteri e mette la deduplica qui.
         *
         * Ed è la stessa scrittura che tiene fresco `last_seen_at`: la pagina
         * rimanda l'iscrizione a ogni visita di chi ha già dato il permesso,
         * ed è quel rinnovo a dare un senso alla finestra di trenta giorni di
         * §15.6 — senza, misurerebbe la data della prima iscrizione.
         */
        $device = $user->devices()->firstOrNew(['endpoint' => $endpoint]);

        $device->forceFill([
            'user_id' => $user->getKey(),
            'platform' => DevicePlatform::Web,
            'endpoint' => $endpoint,
            'keys' => $request->keys(),
            'locale' => $user->locale,
            'last_seen_at' => CarbonImmutable::now(),
            'revoked_at' => null,
        ])->save();

        return response()->json(['message' => __('notifications.push.enabled')], 201);
    }

    /**
     * La revoca. Non cancella la riga: `revoked_at` è ciò che dice a §15.6 di
     * escludere il dispositivo e di tornare all'email al prossimo invio,
     * mentre una riga cancellata tornerebbe identica alla prima iscrizione
     * automatica.
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = Auth::user();

        if ($user === null) {
            return response()->json(['message' => __('notifications.push.unauthenticated')], 401);
        }

        $endpoint = $request->string('endpoint')->value();

        $devices = $user->devices()->whereNull('revoked_at');

        /*
         * Senza endpoint si revocano tutti i browser di questa persona: è il
         * caso di chi spegne l'interruttore dopo aver perso il permesso del
         * browser, quando l'iscrizione da citare non esiste più.
         */
        if ($endpoint !== '') {
            $devices->where('endpoint', $endpoint);
        } else {
            $devices->where('platform', DevicePlatform::Web);
        }

        $devices->update(['revoked_at' => CarbonImmutable::now()]);

        return response()->json(['message' => __('notifications.push.disabled')]);
    }
}
