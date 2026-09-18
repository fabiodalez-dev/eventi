<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommunityStatus;
use App\Enums\PostIntent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommunityPost extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['intent' => PostIntent::class, 'status' => CommunityStatus::class, 'published_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<SavedEvent, $this> */
    public function savedEvent(): BelongsTo
    {
        return $this->belongsTo(SavedEvent::class);
    }

    /** @return BelongsTo<EventOccurrence, $this> */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(EventOccurrence::class);
    }

    /** @return HasMany<CommunityComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(CommunityComment::class);
    }
}
