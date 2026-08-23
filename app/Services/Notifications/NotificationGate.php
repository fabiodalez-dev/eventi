<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\DTOs\NotificationDecision;
use App\DTOs\QuietHours;
use App\Enums\NotificationSkipReason;
use App\Enums\NotificationType;
use App\Models\NotificationLog;
use App\Models\ScheduledNotification;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Le regole di volume di §15.4, applicate al momento dell'invio e non a quello
 * della programmazione.
 *
 * È una scelta, e ha una ragione: fra il salvataggio di una data e il
 * promemoria passano ore o giorni, e in mezzo la persona può aver cambiato
 * idea, aver spento una tipologia, aver dichiarato le proprie ore di silenzio
 * o aver verificato l'indirizzo. Decidere alla programmazione significherebbe
 * decidere con i dati di ieri.
 *
 * L'ordine dei controlli non è casuale:
 *
 * 1. **chi non c'è più** e **chi non ha verificato l'indirizzo** non riceve
 *    nulla, mai (§15.2);
 * 2. **le preferenze** — che le tipologie obbligatorie non hanno (§15.4);
 * 3. **le ore di silenzio**, che spostano e non cancellano;
 * 4. **il tetto giornaliero**, valutato all'orario in cui l'invio partirebbe
 *    davvero: valutarlo prima dello spostamento significherebbe contarlo nel
 *    giorno sbagliato.
 */
final class NotificationGate
{
    public function decide(ScheduledNotification $notification, ?User $user, CarbonImmutable $now): NotificationDecision
    {
        $type = $notification->type();

        if ($type === null) {
            return NotificationDecision::skip(NotificationSkipReason::MissingSubject);
        }

        if (! $user instanceof User || $user->trashed()) {
            return NotificationDecision::skip(NotificationSkipReason::AccountDeleted);
        }

        /*
         * §15.2: «verifica email obbligatoria prima di qualunque invio. Un
         * account non verificato può salvare, non può ricevere.»
         */
        if (! $user->canReceiveNotifications()) {
            return NotificationDecision::skip(NotificationSkipReason::Unverified);
        }

        if (! $type->isEnabledFor($user)) {
            return NotificationDecision::skip(NotificationSkipReason::PreferenceOff);
        }

        if ($type->respectsQuietHours()) {
            $quiet = QuietHours::fromUser($user);
            $local = $now->setTimezone($this->timezone($user));

            if ($quiet instanceof QuietHours && $quiet->contains($local)) {
                return NotificationDecision::defer($quiet->endsAfter($local)->utc());
            }
        }

        if ($type->countsTowardDailyCap() && $this->overDailyCap($user, $now)) {
            return NotificationDecision::skip(NotificationSkipReason::FrequencyCap);
        }

        return NotificationDecision::send();
    }

    /**
     * «Massimo 2 push al giorno per utente, esclusi i promemoria di eventi
     * salvati esplicitamente» (§15.4).
     *
     * Il giorno è quello **locale di chi riceve**, non quello del server: due
     * notifiche alle 23:00 e una alle 00:30 sono due giorni diversi per chi
     * legge e uno solo per un server in UTC.
     */
    private function overDailyCap(User $user, CarbonImmutable $now): bool
    {
        $startOfDay = $now->setTimezone($this->timezone($user))->startOfDay()->utc();

        $sent = NotificationLog::query()
            ->where('user_id', $user->getKey())
            ->whereIn('type', NotificationType::cappedValues())
            ->where('sent_at', '>=', $startOfDay)
            ->count();

        return $sent >= config()->integer('notifications.daily_cap');
    }

    private function timezone(User $user): string
    {
        return $user->timezone !== '' ? $user->timezone : config()->string('app.timezone');
    }
}
