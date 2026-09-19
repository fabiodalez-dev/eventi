<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CatalogReviewStatus;
use App\Support\ContentVersion;
use Codebyray\ReviewRateable\Models\Review;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;

class CatalogReview extends Review
{
    protected $table = 'reviews';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => CatalogReviewStatus::class, 'revision' => 'integer', 'moderated_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        parent::booted();
        $invalidate = static function (self $review): void {
            $city = $review->reviewable?->getAttribute('city_id');
            if ($city !== null) {
                DB::afterCommit(static fn () => ContentVersion::bump((int) $city));
            }
        };
        static::saved($invalidate);
        static::deleted($invalidate);
    }

    /** @return MorphTo<Model, $this> */
    public function reviewable(): MorphTo
    {
        return $this->morphTo();
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

    /** @return HasMany<CatalogRating, $this> */
    public function ratings(): HasMany
    {
        return $this->hasMany(CatalogRating::class, 'review_id');
    }

    /** @return Attribute<int|null, never> */
    protected function rating(): Attribute
    {
        return Attribute::make(get: fn (): ?int => ($value = $this->ratings->firstWhere('key', 'overall')?->value) === null ? null : (int) $value);
    }

    /** @return Attribute<string|null, never> */
    protected function body(): Attribute
    {
        return Attribute::make(get: fn (): ?string => $this->review);
    }
}
