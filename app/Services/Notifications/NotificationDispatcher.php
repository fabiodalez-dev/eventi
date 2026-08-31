<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationSkipReason;
use App\Models\NotificationLog;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Notifications\Scheduled\ScheduledMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Il worker di §15.5, quello che gira ogni cinque minuti.
 *
 * ```
 * preleva le righe pending con send_at <= now, IN LOCK
 *   → applica preferenze, ore di silenzio, tetto giornaliero
 *   → sceglie il canale, invia, scrive notification_log
 *   → segna sent | skipped (con motivo) | failed (retry, max 3)
 * ```
 *
 * **Il prelievo in lock.** Senza Redis non esistono lock distribuiti (D5): il
 * blocco è quello del database. Le righe si prendono con
 * `SELECT ... FOR UPDATE SKIP LOCKED` dentro una transazione, che su MariaDB
 * 10.6 e successive fa esattamente ciò che serve — una seconda esecuzione
 * sovrapposta **salta** le righe già in mano alla prima invece di aspettarle o
 * di prenderle due volte. Il vincolo `dedupe_key UNIQUE` resta l'ultima
 * garanzia, quella che regge anche se tutto il resto sbaglia.
 *
 * **Perché l'invio sta dentro la transazione.** Il messaggio non parte da qui:
 * `ScheduledMessage` è una notifica di coda, quindi «inviare» significa
 * scrivere una riga nella tabella `jobs` — dello stesso database. Stato della
 * riga e messaggio nascono e muoiono insieme, e la connessione SMTP avviene
 * altrove, fuori da qualunque blocco.
 */
final class NotificationDispatcher
{
    public function __construct(
        private readonly NotificationGate $gate,
        private readonly MessageFactory $messages,
    ) {}

    /**
     * @return array{claimed: int, sent: int, skipped: int, deferred: int, failed: int}
     */
    public function run(?int $max = null): array
    {
        $now = CarbonImmutable::now();
        $batch = config()->integer('notifications.batch');
        $max ??= PHP_INT_MAX;

        $summary = ['claimed' => 0, 'sent' => 0, 'skipped' => 0, 'deferred' => 0, 'failed' => 0];

        while ($summary['claimed'] < $max) {
            $size = min($batch, $max - $summary['claimed']);
            /*
             * `use (&$summary)` e non una funzione a freccia: quella lega le
             * variabili **per valore**, quindi i contatori aggiornati dentro
             * la transazione morivano con la chiusura. Il worker faceva il
             * proprio lavoro e restituiva cinque zeri: il comando stampava
             * «nessun invio in attesa» dopo averne spediti quaranta, e
             * `--limit` non limitava niente perché `claimed` non cresceva mai.
             */
            $handled = DB::transaction(function () use ($size, $now, &$summary): int {
                return $this->handleBatch($size, $now, $summary);
            });

            if ($handled < $size) {
                break;
            }
        }

        return $summary;
    }

    /**
     * @param  array{claimed: int, sent: int, skipped: int, deferred: int, failed: int}  $summary
     */
    private function handleBatch(int $size, CarbonImmutable $now, array &$summary): int
    {
        /** @var list<int> $ids */
        $ids = DB::table('scheduled_notifications')
            ->where('status', 'pending')
            ->where('send_at', '<=', $now)
            ->orderBy('send_at')
            ->orderBy('id')
            ->limit($size)
            ->lock('for update skip locked')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($ids === []) {
            return 0;
        }

        $rows = ScheduledNotification::query()
            ->whereIn('id', $ids)
            ->with('user')
            ->get();

        /** @var ScheduledNotification $notification */
        foreach ($rows as $notification) {
            $summary['claimed']++;
            $this->process($notification, $now, $summary);
        }

        return count($ids);
    }

    /**
     * @param  array{claimed: int, sent: int, skipped: int, deferred: int, failed: int}  $summary
     */
    private function process(ScheduledNotification $notification, CarbonImmutable $now, array &$summary): void
    {
        $user = $notification->user;
        $decision = $this->gate->decide($notification, $user, $now);

        if ($decision->deferTo instanceof CarbonImmutable) {
            $notification->deferTo($decision->deferTo);
            $summary['deferred']++;

            return;
        }

        if (! $decision->send || ! $user instanceof User) {
            $notification->markSkipped($decision->reason ?? NotificationSkipReason::AccountDeleted);
            $summary['skipped']++;

            return;
        }

        /*
         * L'ultimo controllo è sul contenuto, e arriva per ultimo di
         * proposito: un invio spostato fuori dalle ore di silenzio torna qui
         * ore dopo, e nel frattempo la serata può essere cominciata. §15.4:
         * «se nel frattempo è diventato inutile viene marcato skipped».
         */
        $message = $this->messages->build($notification, $user);

        if ($message instanceof NotificationSkipReason) {
            $notification->markSkipped($message);
            $summary['skipped']++;

            return;
        }

        try {
            $user->notify(new ScheduledMessage($message));

            NotificationLog::query()->create([
                'user_id' => $user->getKey(),
                'type' => $message->type->value,
                'channel' => $notification->channel,
                'sent_at' => $now,
            ]);

            $notification->markSent($now);
            $summary['sent']++;
        } catch (Throwable $exception) {
            /*
             * Il tentativo successivo è più lontano del precedente (§15.5).
             * Alla terza volta la riga passa a `failed` e resta nel pannello
             * con il proprio errore scritto: un invio che non è partito deve
             * essere leggibile, non dedotto dall'assenza.
             */
            $notification->markAttemptFailed($exception->getMessage(), $now);
            report($exception);
            $summary['failed']++;
        }
    }
}
