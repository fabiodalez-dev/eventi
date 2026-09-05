<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BookingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['request_hash', 'request_key'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => BookingStatus::class, 'cancelled_at' => 'datetime', 'booker_data' => 'encrypted:array', 'privacy_accepted_at' => 'datetime'];
    }

    /** @return BelongsTo<EventOccurrence, $this> */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(EventOccurrence::class, 'occurrence_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<AdmissionTicket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(AdmissionTicket::class)->chaperone();
    }
}
