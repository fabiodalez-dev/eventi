<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RideAccessibility;
use App\Enums\RideLeg;
use App\Enums\RideStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RideOffer extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return BelongsTo<User, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id')->withTrashed();
    }

    /** @return BelongsTo<EventOccurrence, $this> */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(EventOccurrence::class, 'occurrence_id')->withTrashed()->with(['event' => fn ($query) => $query->withTrashed()]);
    }

    /** @return HasMany<RideRequest, $this> */
    public function requests(): HasMany
    {
        return $this->hasMany(RideRequest::class, 'ride_offer_id');
    }

    /**
     * Passaggio verso un evento dimostrativo (`is_demo`), come quelli della
     * vetrina: resta visibile negli elenchi e nella scheda, ma nessuno può
     * chiedere un posto né riceverlo come proposta per una ricerca. Il
     * conducente di prova non risponderebbe mai, e la manutenzione
     * avviserebbe davvero la persona vera della richiesta scaduta.
     */
    public function isDemo(): bool
    {
        return (bool) $this->occurrence?->event?->getAttribute('is_demo');
    }

    /**
     * Solo i passaggi verso eventi veri: la stessa regola di `isDemo()` in una
     * query, anche per le date e gli eventi cestinati.
     *
     * @param  Builder<self>  $query
     */
    public function scopeReal(Builder $query): void
    {
        $query->whereNotIn('occurrence_id', EventOccurrence::withTrashed()
            ->whereIn('event_id', Event::withTrashed()->where('is_demo', true)->select('id'))->select('id'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'leg' => RideLeg::class,
            'status' => RideStatus::class,
            'accessibility' => RideAccessibility::class,
            'departure_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'stops' => 'array',
            'snapshot' => 'array',
            'capacity' => 'integer',
            'revision' => 'integer',
        ];
    }
}
