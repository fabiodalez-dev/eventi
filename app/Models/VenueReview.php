<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VenueReviewStatus;
use App\Support\ContentVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class VenueReview extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['status' => VenueReviewStatus::class, 'rating' => 'integer', 'revision' => 'integer', 'moderated_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        $invalidate = static function (self $review): void {
            $city = $review->venue?->city_id;
            if ($city !== null) {
                DB::afterCommit(static fn () => ContentVersion::bump((int) $city));
            }
        };
        static::saved($invalidate);
        static::deleted($invalidate);
    }

    /** @return BelongsTo<Venue, $this> */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    /** @param Builder<self> $query */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', VenueReviewStatus::Approved)->whereHas('user');
    }
}
