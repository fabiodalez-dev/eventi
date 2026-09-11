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
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class SponsorshipGrant extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        /*
         * Ogni scrittura fa invecchiare le pagine della città e l'impronta
         * delle concessioni attive.
         *
         * La cancellazione c'era rimasta fuori: una concessione tolta non
         * cambiava nessuna chiave, e le pagine che la mostravano restavano in
         * giro fino alla scadenza naturale.
         */
        $invalida = function (self $grant): void {
            $cityId = $grant->venue?->city_id;

            if ($cityId === null) {
                return;
            }

            ContentVersion::bump($cityId);
            ActiveGrants::forget((int) $cityId);
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

    /** @return HasMany<Sponsorship, $this> */
    public function sponsorships(): HasMany
    {
        return $this->hasMany(Sponsorship::class);
    }

    /** @param Builder<SponsorshipGrant> $query */
    public function scopeActive(Builder $query, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now('UTC');
        $query->where('enabled', true)->where('starts_at', '<=', $now)->where('ends_at', '>', $now)
            ->whereHas('venue', fn (Builder $venue) => $venue->where('status', VenueStatus::Approved))
            ->where(fn (Builder $q) => $q->where('complimentary', true)->orWhere(fn (Builder $q) => $q
                ->whereNotNull('paid_at')->where('paid_at', '<=', $now)->where('amount_cents', '>', 0)));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty();
    }

    public function statusLabel(): string
    {
        $now = CarbonImmutable::now('UTC');

        return match (true) {
            ! $this->enabled => __('promotions.revoked'),
            $this->ends_at->lessThanOrEqualTo($now) => __('promotions.expired'),
            ! $this->complimentary && ($this->paid_at === null || $this->paid_at->isAfter($now) || $this->amount_cents < 1) => __('promotions.awaiting_payment'),
            $this->starts_at->isAfter($now) => __('promotions.scheduled'),
            $this->venue?->status !== VenueStatus::Approved => __('promotions.inactive'),
            default => __('promotions.running'),
        };
    }
}
