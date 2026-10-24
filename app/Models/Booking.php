<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BookingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    /** @param Builder<Booking> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Waitlisted]);
    }

    protected $guarded = ['id'];

    protected $hidden = ['request_hash', 'request_key'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => BookingStatus::class, 'cancelled_at' => 'datetime', 'booker_data' => 'encrypted:array', 'booking_answers' => 'encrypted:array',
            'privacy_accepted_at' => 'datetime', 'promotion_expires_at' => 'datetime'];
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

    /** Chi l'ha scritta dal pannello, quando non l'ha fatta la persona da sola. */
    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Una prenotazione presa al banco o al telefono: nessun account dietro,
     * i recapiti stanno in `booker_data`.
     */
    public function isStaffBooking(): bool
    {
        return $this->user_id === null;
    }

    /** L'indirizzo a cui scrivere: l'account, oppure il recapito lasciato al banco. */
    public function contactEmail(): ?string
    {
        $email = $this->user !== null ? $this->user->email : ($this->booker_data['email'] ?? null);

        return is_string($email) && $email !== '' ? $email : null;
    }

    /** @return HasMany<AdmissionTicket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(AdmissionTicket::class)->chaperone();
    }
}
