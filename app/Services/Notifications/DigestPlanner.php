<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\EventStatus;
use App\Enums\FollowableType;
use App\Enums\NotificationType;
use App\Models\Follow;
use App\Models\NotificationLog;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Chi programma i riepiloghi (§15.4) e le due comunicazioni periodiche che non
 * nascono da un gesto dell'utente.
 *
 * La regola che questo file esiste per far rispettare è una sola:
 * **mai una notifica per singolo evento nuovo di un locale seguito**. Chi
 * segue otto locali riceverebbe trenta messaggi a settimana e disinstallerebbe
 * tutto (§15.4). Il riepilogo è aggregato, e la sua riga viene creata in
 * anticipo — perché §15.5 vuole che ogni invio previsto sia visibile **prima**
 * che parta, e un lavoro in coda non lo è.
 *
 * Gli orari sono calcolati nel **fuso di chi riceve**: le 17:00 del riepilogo
 * giornaliero sono le sue, non quelle del server.
 */
final class DigestPlanner
{
    public function __construct(private readonly NotificationScheduler $scheduler) {}

    /**
     * @return array<string, int>
     */
    public function plan(): array
    {
        $now = CarbonImmutable::now();
        $horizon = $now->addHours(config()->integer('notifications.planning_horizon_hours'));

        $planned = [
            NotificationType::VenueDigest->value => 0,
            NotificationType::DailyDigest->value => 0,
            NotificationType::WeekendNewsletter->value => 0,
            NotificationType::VenueInactive->value => 0,
        ];

        $followers = $this->followerIds();

        User::query()
            ->whereNotNull('email_verified_at')
            ->orderBy('id')
            ->chunkById(500, function (Collection $users) use (&$planned, $now, $horizon, $followers): void {
                /** @var User $user */
                foreach ($users as $user) {
                    $this->planForUser($user, $now, $horizon, $followers, $planned);
                }
            });

        $planned[NotificationType::VenueInactive->value] = $this->planVenueInactivity($now);

        return $planned;
    }

    /**
     * §15.9: `notification_log` conserva dodici mesi. La purga gira con la
     * pianificazione perché è l'unica cosa che passa una volta l'ora: farla
     * ogni cinque minuti sarebbe una scansione inutile, farla a mano
     * significherebbe non farla.
     */
    public function purgeLog(): int
    {
        return NotificationLog::query()
            ->where('sent_at', '<', CarbonImmutable::now()->subMonths(config()->integer('notifications.log_retention_months')))
            ->delete();
    }

    /**
     * @param  array<int, bool>  $followers
     * @param  array<string, int>  $planned
     */
    private function planForUser(User $user, CarbonImmutable $now, CarbonImmutable $horizon, array $followers, array &$planned): void
    {
        $preferences = $user->notificationPreferences();
        $timezone = $this->timezone($user);
        $userId = (int) $user->getKey();

        /*
         * Il riepilogo settimanale riguarda chi segue qualcosa. Programmarlo
         * per tutti gli altri produrrebbe, ogni settimana, una riga destinata
         * a essere saltata per mancanza di contenuto: rumore nel pannello che
         * §15.5 vuole leggibile.
         */
        if ($preferences->venueDigest && isset($followers[$userId])) {
            $sendAt = $this->nextWeekday(
                $now,
                $timezone,
                config()->integer('notifications.digests.venue.weekday'),
                config()->string('notifications.digests.venue.time'),
            );

            if ($sendAt->lessThanOrEqualTo($horizon)) {
                $window = config()->integer('notifications.digests.venue.window_days');

                $planned[NotificationType::VenueDigest->value] += (int) $this->scheduler->queue(
                    userId: $userId,
                    type: NotificationType::VenueDigest,
                    dedupeKey: sprintf('venue_digest:user_%d:%s', $userId, $sendAt->setTimezone($timezone)->format('o-\WW')),
                    sendAt: $sendAt,
                    payload: ['since' => $sendAt->subDays($window)->toIso8601String()],
                );
            }
        }

        if ($preferences->dailyDigest) {
            $sendAt = $this->nextDaily($now, $timezone, $this->dailyTime($user));

            if ($sendAt->lessThanOrEqualTo($horizon)) {
                $planned[NotificationType::DailyDigest->value] += (int) $this->scheduler->queue(
                    userId: $userId,
                    type: NotificationType::DailyDigest,
                    dedupeKey: sprintf('daily_digest:user_%d:%s', $userId, $sendAt->setTimezone($timezone)->format('Y-m-d')),
                    sendAt: $sendAt,
                );
            }
        }

        /*
         * La newsletter del weekend è marketing (§15.9): non basta una
         * preferenza, serve il consenso esplicito e datato di
         * `marketing_opt_in_at`.
         */
        if ($user->marketing_opt_in_at !== null) {
            $sendAt = $this->nextWeekday(
                $now,
                $timezone,
                config()->integer('notifications.digests.weekend.weekday'),
                config()->string('notifications.digests.weekend.time'),
            );

            if ($sendAt->lessThanOrEqualTo($horizon)) {
                $planned[NotificationType::WeekendNewsletter->value] += (int) $this->scheduler->queue(
                    userId: $userId,
                    type: NotificationType::WeekendNewsletter,
                    dedupeKey: sprintf('weekend:user_%d:%s', $userId, $sendAt->setTimezone($timezone)->format('o-\WW')),
                    sendAt: $sendAt,
                );
            }
        }
    }

    /**
     * «Ai gestori: non pubblichi da 21 giorni» (§15.4). L'avviso non si ripete
     * più di una volta al mese per locale — è nella chiave di deduplica — e
     * riguarda i soli locali approvati: uno in attesa di approvazione non è
     * inattivo, è in coda.
     */
    private function planVenueInactivity(CarbonImmutable $now): int
    {
        $threshold = $now->subDays(config()->integer('notifications.venue_inactivity_days'));
        $planned = 0;

        Venue::query()
            ->approved()
            ->whereDoesntHave('events', function (Builder $events) use ($threshold): void {
                $events->where('status', EventStatus::Published->value)
                    ->where('published_at', '>=', $threshold);
            })
            ->with('owners')
            ->orderBy('id')
            ->chunkById(200, function (Collection $venues) use (&$planned, $now): void {
                /** @var Venue $venue */
                foreach ($venues as $venue) {
                    /** @var User $owner */
                    foreach ($venue->owners as $owner) {
                        $planned += (int) $this->scheduler->queue(
                            userId: (int) $owner->getKey(),
                            type: NotificationType::VenueInactive,
                            dedupeKey: sprintf(
                                'venue_inactive:user_%d:venue_%d:%s',
                                (int) $owner->getKey(),
                                (int) $venue->getKey(),
                                $now->format('Y-m'),
                            ),
                            sendAt: $now,
                            subject: $venue,
                        );
                    }
                }
            });

        return $planned;
    }

    /**
     * @return array<int, bool>
     */
    private function followerIds(): array
    {
        $ids = [];

        $sources = array_map(
            static fn (FollowableType $type): string => $type->value,
            FollowableType::feedSources(),
        );

        foreach (Follow::query()->whereIn('followable_type', $sources)->distinct()->pluck('user_id') as $id) {
            $ids[(int) $id] = true;
        }

        return $ids;
    }

    private function dailyTime(User $user): string
    {
        $time = $user->daily_digest_time;

        return is_string($time) && $time !== ''
            ? mb_substr($time, 0, 5)
            : config()->string('notifications.digests.daily.time');
    }

    /**
     * Il prossimo giorno della settimana all'ora indicata, nel fuso di chi
     * riceve. Restituito in UTC, che è come si scrive `send_at`.
     */
    private function nextWeekday(CarbonImmutable $now, string $timezone, int $weekday, string $time): CarbonImmutable
    {
        [$hour, $minute] = $this->clock($time);

        $local = $now->setTimezone($timezone);
        $target = $local->setTime($hour, $minute);
        $target = $target->addDays(($weekday - $target->dayOfWeekIso + 7) % 7)->setTime($hour, $minute);

        if ($target->lessThanOrEqualTo($local)) {
            $target = $target->addDays(7)->setTime($hour, $minute);
        }

        return $target->utc();
    }

    private function nextDaily(CarbonImmutable $now, string $timezone, string $time): CarbonImmutable
    {
        [$hour, $minute] = $this->clock($time);

        $local = $now->setTimezone($timezone);
        $target = $local->setTime($hour, $minute);

        if ($target->lessThanOrEqualTo($local)) {
            $target = $target->addDay()->setTime($hour, $minute);
        }

        return $target->utc();
    }

    /**
     * @return array{int, int}
     */
    private function clock(string $time): array
    {
        $parts = array_map(intval(...), explode(':', $time));

        return [$parts[0], $parts[1] ?? 0];
    }

    private function timezone(User $user): string
    {
        return $user->timezone !== '' ? $user->timezone : config()->string('app.timezone');
    }
}
