<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
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
