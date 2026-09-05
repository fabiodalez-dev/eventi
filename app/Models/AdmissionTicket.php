<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdmissionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdmissionTicket extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['code'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => AdmissionStatus::class, 'checked_in_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function displayStatus(): AdmissionStatus
    {
        $date = $this->booking?->occurrence;

        return $this->status === AdmissionStatus::Valid && $date?->effective_ends_at?->isPast()
            ? AdmissionStatus::Expired : $this->status;
    }
}
