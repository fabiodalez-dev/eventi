<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PromotionMode;
use App\Enums\SponsorshipPlacement;
use App\Enums\VenueStatus;
use App\Support\ContentVersion;
use App\Support\Sponsorship\ActiveGrants;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Un'abilitazione a sponsorizzare: un committente, una posizione, un periodo.
 *
 * Il committente e' un locale **oppure** un organizzatore. L'abilitazione di
 * un locale copre gli eventi ospitati li'; quella di un organizzatore copre
 * gli eventi che lo indicano come organizzatore, in qualunque locale.
 *
 * @property-read Venue|null $venue
 * @property-read Organizer|null $organizer
 */
class SponsorshipGrant extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function (self $grant): void {
            if (($grant->venue_id === null) === ($grant->organizer_id === null)) {
                throw new InvalidArgumentException('A sponsorship grant belongs to exactly one of venue or organizer.');
            }
        });

        /*
         * Ogni scrittura fa invecchiare le pagine della città e l'impronta
         * delle concessioni attive.
         *
         * La cancellazione c'era rimasta fuori: una concessione tolta non
         * cambiava nessuna chiave, e le pagine che la mostravano restavano in
         * giro fino alla scadenza naturale.
         */
        $invalida = function (self $grant): void {
            $cityId = $grant->sponsorCityId();

            if ($cityId === null) {
                return;
            }

            ContentVersion::bump($cityId);
            ActiveGrants::forget($cityId);
        };

        static::saved($invalida);
        static::deleted($invalida);
    }

    protected function casts(): array
    {
        return ['mode' => PromotionMode::class, 'placement' => SponsorshipPlacement::class,
            'enabled' => 'boolean', 'complimentary' => 'boolean', 'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime', 'amount_cents' => 'integer'];
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

    /** @return HasMany<Sponsorship, $this> */
    public function sponsorships(): HasMany
    {
        return $this->hasMany(Sponsorship::class);
    }

    public function sponsor(): Venue|Organizer
    {
        if ($this->venue_id !== null) {
            return $this->venue ?? throw new InvalidArgumentException('Venue missing for sponsorship grant '.$this->getKey());
        }

        return $this->organizer ?? throw new InvalidArgumentException('Organizer missing for sponsorship grant '.$this->getKey());
    }

    public function sponsorName(): string
    {
        return (string) $this->sponsor()->name;
    }

    public function sponsorCityId(): ?int
    {
        $cityId = $this->venue_id !== null ? $this->venue?->city_id : $this->organizer?->city_id;

        return $cityId === null ? null : (int) $cityId;
    }

    /** Se questo evento rientra nell'abilitazione. */
    public function coversEvent(Event $event): bool
    {
        return $this->venue_id !== null
            ? (int) $event->venue_id === (int) $this->venue_id
            : (int) $event->organizer_id === (int) $this->organizer_id;
    }

    /**
     * Gli eventi che l'abilitazione copre.
     *
     * @return Builder<Event>
     */
    public function eventsQuery(): Builder
    {
        return $this->venue_id !== null
            ? Event::query()->where('venue_id', $this->venue_id)
            : Event::query()->where('organizer_id', $this->organizer_id);
    }

    /** @param Builder<SponsorshipGrant> $query */
    public function scopeForSponsor(Builder $query, Venue|Organizer $sponsor): void
    {
        $sponsor instanceof Venue
            ? $query->where('venue_id', $sponsor->getKey())
            : $query->where('organizer_id', $sponsor->getKey());
    }

    /** @param Builder<SponsorshipGrant> $query */
    public function scopeActive(Builder $query, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now('UTC');
        $query->where('enabled', true)->where('starts_at', '<=', $now)->where('ends_at', '>', $now)
            ->where(fn (Builder $sponsor) => $sponsor
                ->whereHas('venue', fn (Builder $venue) => $venue->where('status', VenueStatus::Approved))
                ->orWhereHas('organizer', fn (Builder $organizer) => $organizer->where('is_active', true)))
            ->where(fn (Builder $q) => $q->where('complimentary', true)->orWhere(fn (Builder $q) => $q
                ->whereNotNull('paid_at')->where('paid_at', '<=', $now)->where('amount_cents', '>', 0)));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty();
    }

    public function sponsorIsOperational(): bool
    {
        return $this->venue_id !== null
            ? $this->venue?->status === VenueStatus::Approved
            : (bool) $this->organizer?->is_active;
    }

    public function statusLabel(): string
    {
        $now = CarbonImmutable::now('UTC');

        return match (true) {
            ! $this->enabled => __('promotions.revoked'),
            $this->ends_at->lessThanOrEqualTo($now) => __('promotions.expired'),
            ! $this->complimentary && ($this->paid_at === null || $this->paid_at->isAfter($now) || $this->amount_cents < 1) => __('promotions.awaiting_payment'),
            $this->starts_at->isAfter($now) => __('promotions.scheduled'),
            ! $this->sponsorIsOperational() => __('promotions.inactive'),
            default => __('promotions.running'),
        };
    }
}
