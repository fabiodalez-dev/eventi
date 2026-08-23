<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationChannel;
use Database\Factories\NotificationLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    /** @use HasFactory<NotificationLogFactory> */
    use HasFactory;

    protected $table = 'notification_log';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'type',
        'channel',
        'sent_at',
        'opened_at',
        'clicked_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<NotificationLog>  $query
     */
    public function scopeOfType(Builder $query, string $type): void
    {
        $query->where('type', $type);
    }

    /**
     * @param  Builder<NotificationLog>  $query
     */
    public function scopeOfChannel(Builder $query, NotificationChannel $channel): void
    {
        $query->where('channel', $channel);
    }

    /**
     * @param  Builder<NotificationLog>  $query
     */
    public function scopeOpened(Builder $query): void
    {
        $query->whereNotNull('opened_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'sent_at' => 'datetime',
            'opened_at' => 'datetime',
            'clicked_at' => 'datetime',
        ];
    }
}
