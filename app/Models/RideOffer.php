<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RideAccessibility;
use App\Enums\RideLeg;
use App\Enums\RideStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RideOffer extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return BelongsTo<User, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id')->withTrashed();
    }

    /** @return BelongsTo<EventOccurrence, $this> */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(EventOccurrence::class, 'occurrence_id')->withTrashed()->with(['event' => fn ($query) => $query->withTrashed()]);
    }

    /** @return HasMany<RideRequest, $this> */
    public function requests(): HasMany
    {
        return $this->hasMany(RideRequest::class, 'ride_offer_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'leg' => RideLeg::class,
            'status' => RideStatus::class,
            'accessibility' => RideAccessibility::class,
            'departure_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'stops' => 'array',
            'snapshot' => 'array',
            'capacity' => 'integer',
            'revision' => 'integer',
        ];
    }
}
