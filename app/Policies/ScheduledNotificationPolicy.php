<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\NotificationStatus;
use App\Enums\Permission;
use App\Models\ScheduledNotification;
use App\Models\User;

/**
 * Chi può guardare gli invii previsti, e chi può fermarli.
 *
 * La tabella esiste perché §15.5 vuole che ogni invio sia «visibile e
 * verificabile in anticipo». Visibile a chi lavora in redazione: quelle righe
 * contengono l'indirizzo di destinazione di una persona reale e cosa ha messo
 * in agenda, quindi non sono dati di servizio ma dati di qualcun altro.
 *
 * Nessuno le crea e nessuno le modifica dal pannello: nascono da un gesto —
 * un salvataggio, un annullamento, una pianificazione — e l'unica azione
 * ammessa è **fermarne** una che non deve partire.
 */
class ScheduledNotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ViewScheduledNotifications->value);
    }

    public function view(User $user, ScheduledNotification $notification): bool
    {
        return $user->can(Permission::ViewScheduledNotifications->value);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ScheduledNotification $notification): bool
    {
        return false;
    }

    /**
     * Si può annullare solo ciò che non è ancora partito: una riga già inviata
     * è cronaca, e la cronaca non si corregge.
     */
    public function cancel(User $user, ScheduledNotification $notification): bool
    {
        return $notification->status === NotificationStatus::Pending
            && $user->can(Permission::ManageScheduledNotifications->value);
    }

    public function delete(User $user, ScheduledNotification $notification): bool
    {
        return false;
    }
}
