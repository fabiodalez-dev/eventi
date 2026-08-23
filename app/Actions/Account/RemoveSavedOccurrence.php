<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Enums\NotificationStatus;
use App\Models\ScheduledNotification;
use App\Models\User;

/**
 * Togliere una data dai salvataggi.
 *
 * Con la riga se ne vanno i promemoria ancora in attesa: §15.5 vuole che ogni
 * invio previsto sia visibile e verificabile **in anticipo**, e un promemoria
 * per una data che non si segue più sarebbe un invio previsto che nessuno ha
 * chiesto. Le righe già inviate restano: sono cronaca, non programma.
 */
final class RemoveSavedOccurrence
{
    public function __invoke(User $user, int $occurrenceId): bool
    {
        $deleted = $user->savedEvents()
            ->where('occurrence_id', $occurrenceId)
            ->delete();

        if ($deleted === 0) {
            return false;
        }

        ScheduledNotification::query()
            ->where('user_id', $user->getKey())
            ->where('notifiable_type', 'event_occurrence')
            ->where('notifiable_id', $occurrenceId)
            ->where('status', NotificationStatus::Pending->value)
            ->update(['status' => NotificationStatus::Cancelled->value]);

        return true;
    }
}
