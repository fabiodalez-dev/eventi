<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VenueNoticeKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un avviso del locale a chi lo segue: pausa, chiusura, riapertura, altro.
 * Vale sulla scheda pubblica finché `until` non è passato (o per un mese se
 * non ha una scadenza), e parte una volta sola verso i follower.
 */
class VenueNotice extends Model
{
    protected $guarded = ['id'];

    /** Quanto resta in vista un avviso senza scadenza dichiarata. */
    public const DEFAULT_DAYS = 30;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['kind' => VenueNoticeKind::class, 'until' => 'date'];
    }

    /** @return BelongsTo<Venue, $this> */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @param Builder<VenueNotice> $query */
    public function scopeCurrent(Builder $query): void
    {
        $query->where(fn (Builder $valid) => $valid
            ->where(fn (Builder $dated) => $dated->whereNotNull('until')->whereDate('until', '>=', now()->toDateString()))
            ->orWhere(fn (Builder $open) => $open->whereNull('until')->where('created_at', '>=', now()->subDays(self::DEFAULT_DAYS))));
    }
}
