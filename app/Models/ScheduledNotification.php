<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationSkipReason;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use Carbon\CarbonImmutable;
use Database\Factories\ScheduledNotificationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ScheduledNotification extends Model
{
    /** @use HasFactory<ScheduledNotificationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'notifiable_type',
        'notifiable_id',
        'type',
        'channel',
        'send_at',
        'sent_at',
        'status',
        'dedupe_key',
        'payload',
        'attempts',
        'last_error',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<ScheduledNotification>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', NotificationStatus::Pending);
    }

    /**
     * Le righe che il worker deve prendere in mano adesso: in attesa e con
     * l'orario di invio già passato (§15.5).
     *
     * @param  Builder<ScheduledNotification>  $query
     */
    public function scopeDue(Builder $query, CarbonImmutable $now): void
    {
        self::dueScope($query, $now);
    }

    /**
     * La stessa condizione in forma statica, per chi riceve un builder da
     * qualcun altro — il filtro del pannello — e non può invocarvi uno scope.
     *
     * @param  Builder<ScheduledNotification>  $query
     */
    public static function dueScope(Builder $query, CarbonImmutable $now): void
    {
        $query->where('status', NotificationStatus::Pending)
            ->where('send_at', '<=', $now);
    }

    /**
     * @param  Builder<ScheduledNotification>  $query
     */
    public function scopeOfStatus(Builder $query, NotificationStatus $status): void
    {
        $query->where('status', $status);
    }

    /**
     * @param  Builder<ScheduledNotification>  $query
     */
    public function scopeOfChannel(Builder $query, NotificationChannel $channel): void
    {
        $query->where('channel', $channel);
    }

    /**
     * @param  Builder<ScheduledNotification>  $query
     */
    public function scopeOfType(Builder $query, string $type): void
    {
        $query->where('type', $type);
    }

    /**
     * Il tipo di dominio della riga. La colonna resta una stringa — è quanto
     * prescrive §7.10 — ma nessuno la confronta con una stringa scritta a
     * mano: `null` significa che la riga porta un tipo che questa versione del
     * codice non conosce, e il worker la salta invece di indovinare.
     */
    public function type(): ?NotificationType
    {
        return NotificationType::tryFrom((string) $this->type);
    }

    /**
     * Un valore del `payload`, che è l'unico posto in cui una riga porta il
     * proprio contesto: quante ore prima parte un promemoria, da quando
     * guarda indietro un riepilogo, dove si trovava una data prima di essere
     * spostata.
     */
    public function context(string $key, mixed $default = null): mixed
    {
        $payload = $this->payload;

        return is_array($payload) ? ($payload[$key] ?? $default) : $default;
    }

    public function markSent(CarbonImmutable $now): void
    {
        $this->forceFill([
            'status' => NotificationStatus::Sent,
            'sent_at' => $now,
            'attempts' => (int) $this->attempts + 1,
            'last_error' => null,
        ])->save();
    }

    /**
     * «skipped (con motivo)» di §15.5. Il motivo si scrive in `last_error`,
     * l'unica colonna di testo libero della tabella: un invio saltato senza
     * motivo scritto è indistinguibile da un guasto silenzioso.
     */
    public function markSkipped(NotificationSkipReason $reason): void
    {
        $this->forceFill([
            'status' => NotificationStatus::Skipped,
            'last_error' => $reason->value,
        ])->save();
    }

    public function markCancelled(): void
    {
        $this->forceFill(['status' => NotificationStatus::Cancelled])->save();
    }

    /**
     * Un tentativo andato male. Finché restano tentativi la riga torna in
     * attesa con l'orario spostato in avanti (§15.5: retry esponenziale);
     * all'ultimo diventa `failed` e resta nel pannello con il suo errore.
     */
    public function markAttemptFailed(string $error, CarbonImmutable $now): void
    {
        $attempts = (int) $this->attempts + 1;
        $max = config()->integer('notifications.max_attempts');

        /** @var list<int> $backoff */
        $backoff = array_values(config()->array('notifications.retry_backoff_minutes'));
        $minutes = $backoff[$attempts - 1] ?? (int) (end($backoff) ?: 45);

        $this->forceFill([
            'attempts' => $attempts,
            'last_error' => mb_substr($error, 0, 2000),
            'status' => $attempts >= $max ? NotificationStatus::Failed : NotificationStatus::Pending,
            'send_at' => $attempts >= $max ? $this->send_at : $now->addMinutes($minutes),
        ])->save();
    }

    /**
     * Sposta l'invio senza cambiarne lo stato: è ciò che accade quando un
     * orario si muove (§15.5) e quando un invio cade dentro le ore di
     * silenzio (§15.4). La riga resta in attesa, e resta visibile.
     */
    public function deferTo(CarbonImmutable $sendAt): void
    {
        $this->forceFill(['send_at' => $sendAt])->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'status' => NotificationStatus::class,
            'send_at' => 'datetime',
            'sent_at' => 'datetime',
            'payload' => 'array',
            'attempts' => 'integer',
        ];
    }
}
