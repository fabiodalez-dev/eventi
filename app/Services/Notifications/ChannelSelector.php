<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\DevicePlatform;
use App\Enums\NotificationChannel;
use App\Enums\NotificationDelivery;
use App\Models\User;
use App\Models\WebPushSubscription;

/**
 * Su quale canale esce un invio (§15.6, D54).
 *
 * La regola del piano in una riga: «push su device attivo negli ultimi 30
 * giorni → altrimenti email → sempre archivio in-app». L'archivio non compare
 * qui perché non è una scelta: `ScheduledMessage` lo aggiunge comunque.
 *
 * **Perché al momento dell'invio e non a quello della programmazione.** La
 * stessa ragione di `NotificationGate`: fra il salvataggio di una data e il
 * promemoria passano giorni, e in mezzo un browser può aver revocato il
 * permesso o essere semplicemente rimasto chiuso oltre la finestra.
 * `NotificationScheduler` scrive `channel` sulla riga perché quella colonna
 * esiste, ma il valore che finisce in `notification_log` — cioè quello che
 * racconta cos'è successo davvero — è deciso qui.
 *
 * **Perché la decisione non può stare dentro il canale.** `WebPushChannel`
 * quando non trova iscrizioni esce in silenzio: un `via()` che dichiarasse
 * sempre push ed email consegnerebbe due volte a chi ha entrambi, e uno che
 * dichiarasse sempre e solo push non consegnerebbe niente a chi non ha
 * browser iscritti, senza dirlo a nessuno. La scelta è un fatto, e va presa
 * prima, dove si può scrivere nel registro.
 */
final class ChannelSelector
{
    public function for(User $user): NotificationChannel
    {
        $delivery = $user->notificationPreferences()->delivery;
        if ($delivery !== NotificationDelivery::Auto) {
            return match ($delivery) {
                NotificationDelivery::Mail => NotificationChannel::Mail,
                NotificationDelivery::Push => $this->configured() ? NotificationChannel::Push : NotificationChannel::Database,
                NotificationDelivery::Both => $this->configured() ? NotificationChannel::Both : NotificationChannel::Mail,
                default => NotificationChannel::Database,
            };
        }
        if (! $this->configured()) {
            return NotificationChannel::Mail;
        }

        $hasDevice = ($this->webConfigured() && WebPushSubscription::query()
            ->where('user_id', $user->getKey())->usable()->exists())
            || ($this->fcmConfigured() && $user->devices()
                ->active()
                ->whereIn('platform', [DevicePlatform::Android->value, DevicePlatform::Ios->value])
                ->whereNotNull('push_token')
                ->where('last_seen_at', '>=', now()->subDays(config()->integer('notifications.push.device_active_days')))
                ->exists());

        return $hasDevice ? NotificationChannel::Push : NotificationChannel::Mail;
    }

    /**
     * Senza chiavi VAPID il canale non esiste: il servizio push rifiuta una
     * richiesta non firmata, e il pacchetto se ne accorge solo al momento
     * della consegna — cioè dentro il job in coda, dove l'errore diventa un
     * tentativo fallito invece di un'email consegnata.
     *
     * È anche ciò che tiene il canale spento in sviluppo e nei test finché
     * qualcuno non genera le chiavi di proposito, ed è la stessa condizione
     * che decide se la pagina delle preferenze mostra l'interruttore: offrire
     * un interruttore che il server ignora è il difetto che quella pagina
     * dichiara di non voler avere.
     */
    public function configured(): bool
    {
        return $this->webConfigured() || $this->fcmConfigured();
    }

    public function webConfigured(): bool
    {
        return filled(config('webpush.vapid.public_key'))
            && filled(config('webpush.vapid.private_key'));
    }

    public function fcmConfigured(): bool
    {
        return config()->boolean('api.features.push')
            && filled(config('firebase.projects.app.credentials'));
    }
}
