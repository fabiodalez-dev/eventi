<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FollowableType;
use Database\Factories\FollowFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'notify' => 'boolean',
        ];
    }
}
