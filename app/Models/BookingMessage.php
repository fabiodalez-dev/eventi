<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un messaggio che il locale ha scritto a chi ha prenotato una data.
 *
 * È la memoria di ciò che è già stato detto: chi apre il pannello vede che
 * «porta la tessera» è partito ieri e non lo rimanda. Il testo si conserva
 * così com'è stato spedito, perché una lamentela arriva sempre dopo.
 */
class BookingMessage extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['include_waitlisted' => 'boolean'];
    }

    /** @return BelongsTo<EventOccurrence, $this> */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(EventOccurrence::class, 'occurrence_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
