<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RideRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RideRequest extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return HasOne<RideConversation, $this> */
    public function conversation(): HasOne
    {
        return $this->hasOne(RideConversation::class);
    }

    /** @return HasOne<RideReview, $this> */
    public function review(): HasOne
    {
        return $this->hasOne(RideReview::class)->withTrashed();
    }

    /** @return BelongsTo<RideOffer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(RideOffer::class, 'ride_offer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => RideRequestStatus::class,
            'note' => 'encrypted',
            'seats' => 'integer',
            'accepted_at' => 'immutable_datetime',
            'passenger_confirmed_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'companions_adult' => 'boolean',
        ];
    }
}
