<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationSkipReason;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Enums\OccurrenceStatus;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\SavedEvent;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Chi scrive le righe di `scheduled_notifications` (§15.5).
 *
 * L'architettura di §15.5 è esplicita su ciò che **non** si fa: nessun cron che
 * ogni minuto scandaglia i salvataggi cercando cosa mandare. Un invio nasce nel
 * momento in cui diventa prevedibile — quando qualcuno salva una data, quando
 * un orario si sposta, quando una serata viene annullata — e da quel momento è
 * una riga visibile nel pannello, con la sua chiave di deduplica.
 *
 * Tutti gli inserimenti passano da `insertOrIgnore`: la `dedupe_key` è unica a
 * livello di database (§7.10) ed è, senza Redis e senza lock distribuito (D5),
 * la sola garanzia reale contro il doppio invio. Chiedere due volte la stessa
 * cosa non è un errore — semplicemente non aggiunge nulla.
 */
final class NotificationScheduler
{
    /**
     * I promemoria per chi ha già in agenda questa data. È il gancio del
     * salvataggio: `SaveOccurrences` lo chiama dopo aver scritto le righe, e
     * lo chiama anche l'auto-salvataggio di chi segue un evento (§15.3).
     *
     * @param  array<int, int>  $userIds
     */
    public function remindersFor(EventOccurrence $occurrence, array $userIds): int
    {
        return $this->remindersForMany([$occurrence], $userIds);
    }

    /**
     * Gli stessi promemoria per più date in una volta sola: è la forma che
     * serve alla migrazione dei salvataggi di un anonimo (§15.1), che arriva
     * con decine di identificativi insieme. Le preferenze di chi riceve si
     * leggono **una volta**, non una per data.
     *
     * @param  iterable<int, EventOccurrence>  $occurrences
     * @param  array<int, int>  $userIds
     */
    public function remindersForMany(iterable $occurrences, array $userIds): int
    {
        $userIds = array_values(array_unique(array_map(intval(...), $userIds)));

        if ($userIds === []) {
            return 0;
        }

        $now = CarbonImmutable::now();
        $users = User::query()->whereIn('id', $userIds)->get();
        $rows = [];

        foreach ($occurrences as $occurrence) {
            if (! $this->isFuture($occurrence)) {
                continue;
            }

            $startsAt = $this->startsAt($occurrence);

            /** @var User $user */
            foreach ($users as $user) {
                foreach ($user->notificationPreferences()->reminderHours as $hours) {
                    $sendAt = $startsAt->subHours($hours);

                    /*
                     * Un promemoria il cui orario è già passato non si crea:
                     * non sarebbe un promemoria ma un ritardo. Chi salva una
                     * data per domani sera riceve quello a tre ore, non quello
                     * a ventiquattro.
                     */
                    if ($sendAt->lessThanOrEqualTo($now)) {
                        continue;
                    }

                    $rows[] = $this->row(
                        userId: (int) $user->getKey(),
                        type: NotificationType::EventReminder,
                        dedupeKey: sprintf('reminder_%dh:user_%d:occ_%d', $hours, (int) $user->getKey(), (int) $occurrence->getKey()),
                        sendAt: $sendAt,
                        subject: $occurrence,
                        payload: ['hours' => $hours],
                        now: $now,
                    );
                }
            }
        }

        return $this->insert($rows);
    }

    /**
     * Gli stessi promemoria, per tutti quelli che hanno già salvato la data.
     */
    public function remindersForSavers(EventOccurrence $occurrence): int
    {
        return $this->remindersFor($occurrence, $this->savers($occurrence));
    }

    /**
     * L'orario si è spostato (§15.5): i promemoria in attesa **si aggiornano**,
     * non se ne creano di nuovi. È la differenza fra riprogrammare e duplicare,
     * ed è ciò che verifica lo scenario I di §18.
     *
     * Se il nuovo orario è già passato, il promemoria non ha più nulla da
     * ricordare: diventa `skipped`, con il suo motivo scritto.
     */
    public function reschedule(EventOccurrence $occurrence): int
    {
        $now = CarbonImmutable::now();
        $startsAt = $this->startsAt($occurrence);
        $moved = 0;

        $pending = ScheduledNotification::query()
            ->pending()
            ->ofType(NotificationType::EventReminder->value)
            ->where('notifiable_type', $occurrence->getMorphClass())
            ->where('notifiable_id', $occurrence->getKey())
            ->get();

        /** @var ScheduledNotification $notification */
        foreach ($pending as $notification) {
            $hours = (int) $notification->context('hours', 0);
            $sendAt = $startsAt->subHours($hours);

            if ($sendAt->lessThanOrEqualTo($now)) {
                $notification->markSkipped(NotificationSkipReason::OccurrencePast);

                continue;
            }

            $notification->deferTo($sendAt);
            $moved++;
        }

        return $moved;
    }

    /**
     * La serata è stata annullata (§15.5): i promemoria in attesa vengono
     * annullati **e** viene accodato l'avviso di annullamento, che è
     * obbligatorio e raggiunge anche chi aveva spento tutto il resto
     * (§18, scenario J).
     */
    public function announceCancellation(EventOccurrence $occurrence): int
    {
        $savers = $this->savers($occurrence);

        $this->cancelPending($occurrence, NotificationType::EventCancelled);

        if ($savers === []) {
            return 0;
        }

        $now = CarbonImmutable::now();

        return $this->insert(array_map(
            fn (int $userId): array => $this->row(
                userId: $userId,
                type: NotificationType::EventCancelled,
                dedupeKey: sprintf('cancelled:user_%d:occ_%d', $userId, (int) $occurrence->getKey()),
                sendAt: $now,
                subject: $occurrence,
                payload: ['starts_at' => $this->startsAt($occurrence)->toIso8601String()],
                now: $now,
            ),
            $savers,
        ));
    }

    /**
     * La data si è spostata e chi l'aveva in agenda deve saperlo (§15.4): è
     * l'altra metà della riga «annullato o spostato», e come l'annullamento
     * non ha interruttore.
     *
     * La chiave porta il **nuovo** istante: due spostamenti successivi sono due
     * notizie diverse, mentre due salvataggi dello stesso spostamento sono la
     * stessa notizia e il vincolo unico li fonde.
     */
    public function announceMove(EventOccurrence $occurrence, ?DateTimeInterface $previousStartsAt): int
    {
        $savers = $this->savers($occurrence);

        if ($savers === [] || ! $this->isFuture($occurrence)) {
            return 0;
        }

        $now = CarbonImmutable::now();
        $startsAt = $this->startsAt($occurrence);

        return $this->insert(array_map(
            fn (int $userId): array => $this->row(
                userId: $userId,
                type: NotificationType::EventMoved,
                dedupeKey: sprintf('moved:user_%d:occ_%d:%d', $userId, (int) $occurrence->getKey(), $startsAt->getTimestamp()),
                sendAt: $now,
                subject: $occurrence,
                payload: [
                    'starts_at' => $startsAt->toIso8601String(),
                    'previous_starts_at' => $previousStartsAt === null
                        ? null
                        : CarbonImmutable::instance($previousStartsAt)->utc()->toIso8601String(),
                ],
                now: $now,
            ),
            $savers,
        ));
    }

    /**
     * Biglietti esauriti (§15.4): immediato, ma con interruttore — chi non lo
     * vuole non lo riceve, e il tetto giornaliero lo conta.
     */
    public function announceSoldOut(EventOccurrence $occurrence): int
    {
        $savers = $this->savers($occurrence);

        if ($savers === [] || ! $this->isFuture($occurrence)) {
            return 0;
        }

        $now = CarbonImmutable::now();

        return $this->insert(array_map(
            fn (int $userId): array => $this->row(
                userId: $userId,
                type: NotificationType::EventSoldOut,
                dedupeKey: sprintf('sold_out:user_%d:occ_%d', $userId, (int) $occurrence->getKey()),
                sendAt: $now,
                subject: $occurrence,
                payload: [],
                now: $now,
            ),
            $savers,
        ));
    }

    /**
     * «Ai gestori: evento pubblicato» (§15.4). Raggiunge tutti quelli che
     * hanno accesso al locale, referente e collaboratori: chi ha scritto la
     * scheda può non essere chi la sorveglia.
     */
    public function announceEventPublished(Event $event): int
    {
        return $this->announceToVenueStaff(
            $event,
            NotificationType::EventPublished,
            fn (int $userId): string => sprintf('event_published:user_%d:event_%d', $userId, (int) $event->getKey()),
        );
    }

    /**
     * «Ai gestori: evento rifiutato» (§15.4). La chiave porta il giorno: un
     * rifiuto ripetuto con una motivazione diversa è un'altra notizia, ma due
     * salvataggi dello stesso rifiuto nello stesso giorno sono la stessa.
     */
    public function announceEventRejected(Event $event): int
    {
        $day = CarbonImmutable::now()->format('Y-m-d');

        return $this->announceToVenueStaff(
            $event,
            NotificationType::EventRejected,
            fn (int $userId): string => sprintf('event_rejected:user_%d:event_%d:%s', $userId, (int) $event->getKey(), $day),
        );
    }

    /**
     * @param  callable(int): string  $dedupeKey
     */
    private function announceToVenueStaff(Event $event, NotificationType $type, callable $dedupeKey): int
    {
        $venue = $event->venue;

        if (! $venue instanceof Venue) {
            return 0;
        }

        $now = CarbonImmutable::now();
        $rows = [];

        /** @var User $member */
        foreach ($venue->members as $member) {
            $rows[] = $this->row(
                userId: (int) $member->getKey(),
                type: $type,
                dedupeKey: $dedupeKey((int) $member->getKey()),
                sendAt: $now,
                subject: $event,
                payload: [],
                now: $now,
            );
        }

        return $this->insert($rows);
    }

    /**
     * Un invio singolo, per tutto ciò che non riguarda i salvataggi: i
     * riepiloghi e le comunicazioni a chi gestisce un locale.
     *
     * @param  array<string, mixed>  $payload
     */
    public function queue(
        int $userId,
        NotificationType $type,
        string $dedupeKey,
        CarbonImmutable $sendAt,
        ?Model $subject = null,
        array $payload = [],
    ): bool {
        $now = CarbonImmutable::now();

        return $this->insert([
            $this->row($userId, $type, $dedupeKey, $sendAt, $subject, $payload, $now),
        ]) > 0;
    }

    /**
     * Annulla gli invii ancora in attesa collegati a una data. Serve
     * all'annullamento della serata e a chi toglie la data dai salvataggi:
     * un invio previsto che nessuno vuole più non deve restare in coda.
     */
    public function cancelPending(EventOccurrence $occurrence, ?NotificationType $except = null): int
    {
        $query = ScheduledNotification::query()
            ->where('status', NotificationStatus::Pending->value)
            ->where('notifiable_type', $occurrence->getMorphClass())
            ->where('notifiable_id', $occurrence->getKey());

        if ($except instanceof NotificationType) {
            $query->where('type', '!=', $except->value);
        }

        return $query->update(['status' => NotificationStatus::Cancelled->value]);
    }

    /**
     * @return list<int>
     */
    private function savers(EventOccurrence $occurrence): array
    {
        /** @var list<int> $ids */
        $ids = SavedEvent::query()
            ->where('occurrence_id', $occurrence->getKey())
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $ids;
    }

    private function startsAt(EventOccurrence $occurrence): CarbonImmutable
    {
        return CarbonImmutable::instance($occurrence->starts_at)->utc();
    }

    /**
     * Una data già cominciata non genera più nulla: né promemoria, né avvisi
     * di spostamento. L'annullamento fa eccezione e non passa da qui — chi
     * arriva davanti a una porta chiusa ha comunque diritto di saperlo prima.
     */
    private function isFuture(EventOccurrence $occurrence): bool
    {
        return $this->startsAt($occurrence)->greaterThan(CarbonImmutable::now())
            && $occurrence->status !== OccurrenceStatus::Cancelled;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function row(
        int $userId,
        NotificationType $type,
        string $dedupeKey,
        CarbonImmutable $sendAt,
        ?Model $subject,
        array $payload,
        CarbonImmutable $now,
    ): array {
        return [
            'user_id' => $userId,
            'notifiable_type' => $subject?->getMorphClass(),
            'notifiable_id' => $subject?->getKey(),
            'type' => $type->value,
            'channel' => NotificationChannel::Mail->value,
            'send_at' => $sendAt->utc(),
            'status' => NotificationStatus::Pending->value,
            'dedupe_key' => $dedupeKey,
            'payload' => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function insert(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        return DB::table('scheduled_notifications')->insertOrIgnore($rows);
    }
}
