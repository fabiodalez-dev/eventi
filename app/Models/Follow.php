<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FollowableType;
use App\Enums\FollowNotificationMode;
use Database\Factories\FollowFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property FollowNotificationMode $notification_mode
 */
class Follow extends Model
{
    /** @use HasFactory<FollowFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'followable_type',
        'followable_id',
        'notify',
        'notification_mode',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function followable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<Follow>  $query
     */
    public function scopeNotifying(Builder $query): void
    {
        $query->where('notify', true);
    }

    /**
     * @param  Builder<Follow>  $query
     */
    public function scopeOfType(Builder $query, FollowableType $type): void
    {
        $query->where('followable_type', $type->value);
    }

    /** Un follow che cambia svuota la lettura della richiesta (`User::followsForRequest()`). */
    protected static function booted(): void
    {
        $forget = static fn (Follow $follow) => request()->attributes->remove(User::followsCacheKey((int) $follow->user_id));
        static::saved($forget);
        static::deleted($forget);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'notify' => 'boolean',
            'notification_mode' => FollowNotificationMode::class,
        ];
    }
}
