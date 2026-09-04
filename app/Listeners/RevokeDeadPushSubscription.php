<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\WebPushSubscription;
use Illuminate\Support\Facades\Log;
use NotificationChannels\WebPush\Events\NotificationFailed;

/**
 * Cosa succede quando il servizio push rifiuta una consegna (§15.6, D54).
 *
 * Due esiti diversi, e la differenza conta:
 *
 * - **iscrizione scaduta** (404 o 410: il browser l'ha revocata, i dati del
 *   sito sono stati cancellati, il profilo non c'è più). Non tornerà valida:
 *   il dispositivo si revoca, e al giro successivo `ChannelSelector` non lo
 *   trova più e ripiega sull'email. È il «token invalidi o rifiutati →
 *   revoked_at» di §15.6;
 * - **qualunque altro guasto** (il servizio push è giù, il carico è troppo,
 *   il payload è stato rifiutato). Il dispositivo è ancora buono e revocarlo
 *   sposterebbe su email qualcuno che ha solo avuto sfortuna. Resta una riga
 *   nel registro, perché un invio che non è partito deve essere leggibile e
 *   non dedotto dall'assenza — qui non c'è nessuna riga di
 *   `scheduled_notifications` che possa raccontarlo: la consegna avviene
 *   dentro il job in coda, molto dopo che il worker ha segnato l'invio.
 *
 * La revoca passa comunque due volte: il gestore dei referti del pacchetto
 * chiama `delete()` sull'iscrizione scaduta, e `WebPushSubscription::delete()`
 * scrive `revoked_at` invece di cancellare. Quella è la difesa della riga —
 * vale per chiunque chiami `delete()` — mentre questa è la regola di §15.6
 * scritta dove si legge. Sono idempotenti: la seconda non cambia nulla.
 */
final class RevokeDeadPushSubscription
{
    public function handle(NotificationFailed $event): void
    {
        $endpoint = $event->report->getEndpoint();

        if (! $event->report->isSubscriptionExpired()) {
            Log::warning('Consegna push rifiutata', [
                'endpoint' => $endpoint,
                'motivo' => $event->report->getReason(),
            ]);

            return;
        }

        /*
         * Per endpoint e non per chiave primaria: l'evento porta un modello
         * che il pacchetto ha costruito, e l'endpoint è ciò che identifica
         * un'iscrizione presso il servizio push. Lo scope globale limita già
         * la ricerca alle righe di `devices` non revocate.
         */
        WebPushSubscription::query()
            ->where('endpoint', $endpoint)
            ->get()
            ->each(static fn (WebPushSubscription $subscription) => $subscription->delete());
    }
}
