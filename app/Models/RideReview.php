<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommunityStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RideReview extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    /** @return BelongsTo<RideRequest, $this> */
    public function rideRequest(): BelongsTo
    {
        return $this->belongsTo(RideRequest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id')->withTrashed();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => CommunityStatus::class, 'rating' => 'integer', 'revision' => 'integer', 'moderated_at' => 'immutable_datetime'];
    }
}
