<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RideAccessibility;
use App\Enums\RideLeg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RideSearch extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<EventOccurrence, $this> */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(EventOccurrence::class, 'occurrence_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'leg' => RideLeg::class,
            'accessibility' => RideAccessibility::class,
            'is_public' => 'boolean',
            'alerts_enabled' => 'boolean',
            'active' => 'boolean',
            'seats' => 'integer',
            'earliest_at' => 'immutable_datetime',
            'latest_at' => 'immutable_datetime',
        ];
    }
}
