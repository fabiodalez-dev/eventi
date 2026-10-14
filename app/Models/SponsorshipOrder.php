<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PromotionMode;
use App\Enums\SponsorshipOrderStatus;
use App\Enums\SponsorshipPlacement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Number;
use InvalidArgumentException;

/**
 * Un ordine di sponsorizzazione fatto dal pannello di un locale o di un
 * organizzatore.
 *
 * Il committente e' uno dei due, mai entrambi: `sponsor()` lo restituisce
 * senza che chi legge debba ricordarsi quale colonna guardare. Il passaggio
 * di stato passa da `transitionTo()`, che rifiuta le mosse non previste: un
 * ordine rifiutato non torna pagato per un webhook arrivato in ritardo.
 *
 * `checkout_url` non e' una colonna: e' l'indirizzo della pagina di pagamento
 * appena aperta, che serve una volta sola, subito.
 *
 * @property-read Venue|null $venue
 * @property-read Organizer|null $organizer
 * @property-read User|null $user
 * @property-read SponsorshipGrant|null $grant
 */
class SponsorshipOrder extends Model
{
    protected $guarded = ['id'];

    public ?string $checkout_url = null;

    protected static function booted(): void
    {
        static::saving(function (self $order): void {
            if (($order->venue_id === null) === ($order->organizer_id === null)) {
                throw new InvalidArgumentException('A sponsorship order belongs to exactly one of venue or organizer.');
            }
        });
    }

    /** @return BelongsTo<Venue, $this> */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /** @return BelongsTo<Organizer, $this> */
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return BelongsTo<SponsorshipPrice, $this> */
    public function price(): BelongsTo
    {
        return $this->belongsTo(SponsorshipPrice::class, 'sponsorship_price_id');
    }

    /** @return BelongsTo<SponsorshipGrant, $this> */
    public function grant(): BelongsTo
    {
        return $this->belongsTo(SponsorshipGrant::class, 'sponsorship_grant_id');
    }

    public function sponsor(): Venue|Organizer
    {
        if ($this->venue_id !== null) {
            return $this->venue ?? throw new InvalidArgumentException('Venue missing for sponsorship order '.$this->getKey());
        }

        return $this->organizer ?? throw new InvalidArgumentException('Organizer missing for sponsorship order '.$this->getKey());
    }

    public function sponsorName(): string
    {
        return (string) $this->sponsor()->name;
    }

    public function belongsToSponsor(Venue|Organizer $sponsor): bool
    {
        return $sponsor instanceof Venue
            ? (int) $this->venue_id === (int) $sponsor->getKey()
            : (int) $this->organizer_id === (int) $sponsor->getKey();
    }

    /** @param Builder<SponsorshipOrder> $query */
    public function scopeForSponsor(Builder $query, Venue|Organizer $sponsor): void
    {
        $sponsor instanceof Venue
            ? $query->where('venue_id', $sponsor->getKey())
            : $query->where('organizer_id', $sponsor->getKey());
    }

    public function transitionTo(SponsorshipOrderStatus $next): void
    {
        if (! $this->status->canBecome($next)) {
            throw new InvalidArgumentException(sprintf('Sponsorship order %d cannot go from %s to %s.', $this->getKey(), $this->status->value, $next->value));
        }

        $this->status = $next;
    }

    public function formattedAmount(): string
    {
        return (string) Number::currency($this->amount_cents / 100, in: $this->currency ?: 'EUR', locale: app()->getLocale());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'placement' => SponsorshipPlacement::class,
            'mode' => PromotionMode::class,
            'status' => SponsorshipOrderStatus::class,
            'months' => 'integer',
            'amount_cents' => 'integer',
            'paid_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }
}
