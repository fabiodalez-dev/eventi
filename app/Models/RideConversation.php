<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RideConversation extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return BelongsTo<RideRequest, $this> */
    public function rideRequest(): BelongsTo
    {
        return $this->belongsTo(RideRequest::class, 'ride_request_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'read_only_at' => 'immutable_datetime',
            'hidden_at' => 'immutable_datetime',
            'purged_at' => 'immutable_datetime',
        ];
    }
}
