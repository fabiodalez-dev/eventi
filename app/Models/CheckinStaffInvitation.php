<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un invito al personale di ingresso di una data, ancora senza risposta (D67).
 *
 * @property int $id
 * @property int $occurrence_id
 * @property string $email
 * @property int|null $invited_by_id
 * @property CarbonImmutable $invited_at
 */
class CheckinStaffInvitation extends Model
{
    use Prunable;

    /** Quanto vale il collegamento della mail. */
    public const EXPIRES_DAYS = 7;

    /** Inviti in sospeso per data: oltre, chi gestisce aspetta le risposte o ritira qualcuno. */
    public const MAX_PENDING = 30;

    protected $table = 'occurrence_staff_invitations';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['invited_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<EventOccurrence, $this> */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(EventOccurrence::class, 'occurrence_id');
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('invited_at', '<', now()->subDays(self::EXPIRES_DAYS + 30));
    }
}
