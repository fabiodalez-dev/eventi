<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RideFeedbackKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RideFeedback extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<RideRequest, $this> */
    public function rideRequest(): BelongsTo
    {
        return $this->belongsTo(RideRequest::class, 'ride_request_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => RideFeedbackKind::class,
            'body' => 'encrypted',
        ];
    }
}
