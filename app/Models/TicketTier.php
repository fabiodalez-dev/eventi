<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TicketTierStatus;
use Database\Factories\TicketTierFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una fascia di biglietto: settore, prezzo, stato.
 *
 * Lo **stato sta qui**, non sull'occorrenza: `OccurrenceStatus::SoldOut` marca
 * l'intera serata e non sa dire che è finito solo il parterre. Le due cose
 * convivono — una data può essere annullata mentre il listino resta a
 * documentare quanto costava — e la scheda pubblica legge prima lo stato della
 * data, poi quello delle fasce.
 *
 * `occurrence_id` nullo significa "listino dell'evento", valorizzato "listino
 * di questa data soltanto": la risoluzione la fa `App\Support\TicketTiers`, ed
 * è l'unico punto in cui la regola è scritta.
 */
class TicketTier extends Model
{
    /** @use HasFactory<TicketTierFactory> */
    use HasFactory;

    protected $table = 'ticket_tiers';

    /** @var list<string> */
    protected $fillable = [
        'event_id',
        'occurrence_id',
        'name',
        'price',
        'currency',
        'status',
        'url',
        'note',
        'sort_order',
    ];

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<EventOccurrence, $this> */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(EventOccurrence::class, 'occurrence_id');
    }

    /**
     * Il listino che vale per tutte le date dell'evento.
     *
     * @param  Builder<TicketTier>  $query
     */
    public function scopeForEventListing(Builder $query): void
    {
        $query->whereNull('occurrence_id');
    }

    /**
     * Se da questa fascia si compra adesso: serve al pulsante di acquisto e al
     * tono dell'etichetta nella tabella.
     */
    public function isOnSale(): bool
    {
        return $this->status->isOnSale();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TicketTierStatus::class,
            'price' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }
}
