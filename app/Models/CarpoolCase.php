<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CarpoolCaseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CarpoolCase extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return BelongsTo<RideRequest, $this> */
    public function rideRequest(): BelongsTo
    {
        return $this->belongsTo(RideRequest::class, 'ride_request_id');
    }

    /** @return BelongsTo<RideOffer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(RideOffer::class, 'ride_offer_id');
    }

    /** @return HasMany<CarpoolCaseMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(CarpoolCaseMessage::class, 'carpool_case_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CarpoolCaseStatus::class,
            'body' => 'encrypted',
            'review_snapshot' => 'encrypted:array',
            'closed_at' => 'immutable_datetime',
            'hold_until' => 'immutable_datetime',
        ];
    }
}
