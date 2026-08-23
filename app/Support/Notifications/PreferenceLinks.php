<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Enums\NotificationType;
use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * I due collegamenti che §15.9 pretende in fondo a ogni email: la
 * **disiscrizione a un click** e la **pagina delle preferenze raggiungibile
 * senza accesso**.
 *
 * Sono indirizzi firmati e a scadenza. Firmati perché altrimenti chiunque
 * conoscesse un identificativo numerico potrebbe spegnere le notifiche di
 * qualcun altro; a scadenza perché un messaggio inoltrato o finito in un
 * archivio pubblico non deve restare una chiave per sempre. La durata è quella
 * utile di un messaggio nella casella di chi lo riceve, non quella di una
 * credenziale.
 */
final class PreferenceLinks
{
    public static function preferences(User $user): string
    {
        return URL::temporarySignedRoute(
            'notifications.preferences',
            self::expiry(),
            ['user' => (int) $user->getKey()],
        );
    }

    /**
     * `null` per le tipologie che non si possono spegnere (§15.4): un
     * collegamento di disiscrizione che non disiscrive sarebbe una presa in
     * giro, e mostrarlo sarebbe peggio che non averlo.
     */
    public static function unsubscribe(User $user, NotificationType $type): ?string
    {
        if ($type->isMandatory() || $type->isForVenueStaff()) {
            return null;
        }

        return URL::temporarySignedRoute(
            'notifications.unsubscribe',
            self::expiry(),
            ['user' => (int) $user->getKey(), 'type' => $type->value],
        );
    }

    /**
     * Lo stesso indirizzo per il metodo `POST`, che è quello che i client di
     * posta chiamano da soli quando trovano l'intestazione `List-Unsubscribe`
     * insieme a `List-Unsubscribe-Post` (RFC 8058). È il click che l'utente
     * non deve nemmeno fare.
     */
    public static function unsubscribePost(User $user, NotificationType $type): ?string
    {
        if ($type->isMandatory() || $type->isForVenueStaff()) {
            return null;
        }

        return URL::temporarySignedRoute(
            'notifications.unsubscribe.submit',
            self::expiry(),
            ['user' => (int) $user->getKey(), 'type' => $type->value],
        );
    }

    private static function expiry(): \DateTimeInterface
    {
        return now()->addDays(config()->integer('notifications.preference_link_days'));
    }
}
