<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OccurrenceStatus;
use Database\Factories\EventOccurrenceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Nessuno scope temporale vive qui: ogni finestra ("oggi", "stasera",
 * "in corso", "inizia tra poco") sta in App\Queries\EventOccurrenceQuery.
 */
class EventOccurrence extends Model
{
    /** @use HasFactory<EventOccurrenceFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'event_occurrences';

    /** @var list<string> */
    protected $fillable = [
        'event_id',
        'recurrence_id',
        'starts_at',
        'ends_at',
        'effective_ends_at',
        'doors_at',
        'is_all_day',
        'business_date',
        'status',
        'status_note',
        'price_override',
        'capacity',
        'capacity_left',
        'booking_enabled',
        'booking_capacity',
        'booking_limit',
        'booking_waitlist',
        'booking_opens_at',
        'booking_closes_at',
        'cancellation_closes_at',
        'booking_instructions',
        'booking_fields',
        'highlight',
        'is_exception',
    ];

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<EventRecurrence, $this> */
    public function recurrence(): BelongsTo
    {
        return $this->belongsTo(EventRecurrence::class, 'recurrence_id');
    }

    /**
     * Il listino di **questa data soltanto**, quando esiste: sostituisce
     * quello dell'evento invece di integrarlo (vedi `App\Support\TicketTiers`).
     *
     * @return HasMany<TicketTier, $this>
     */
    public function ticketTiers(): HasMany
    {
        return $this->hasMany(TicketTier::class, 'occurrence_id')->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<Lineup, $this> */
    public function lineups(): HasMany
    {
        return $this->hasMany(Lineup::class, 'occurrence_id');
    }

    /** @return HasMany<SavedEvent, $this> */
    public function savedEvents(): HasMany
    {
        return $this->hasMany(SavedEvent::class, 'occurrence_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function savedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'saved_events', 'occurrence_id', 'user_id')
            ->withPivot(['reminder_sent_at'])
            ->withTimestamps();
    }

    /** @return MorphMany<ScheduledNotification, $this> */
    public function scheduledNotifications(): MorphMany
    {
        return $this->morphMany(ScheduledNotification::class, 'notifiable');
    }

    /** @return MorphMany<Report, $this> */
    public function reports(): MorphMany
    {
        return $this->morphMany(Report::class, 'reportable');
    }

    /**
     * @param  Builder<EventOccurrence>  $query
     */
    public function scopeScheduled(Builder $query): void
    {
        $query->where('status', OccurrenceStatus::Scheduled);
    }

    /**
     * @param  Builder<EventOccurrence>  $query
     */
    public function scopeOfStatus(Builder $query, OccurrenceStatus $status): void
    {
        $query->where('status', $status);
    }

    /**
     * @param  Builder<EventOccurrence>  $query
     */
    public function scopeExceptions(Builder $query): void
    {
        $query->where('is_exception', true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'effective_ends_at' => 'datetime',
            'doors_at' => 'datetime',
            'business_date' => 'date',
            'is_all_day' => 'boolean',
            'status' => OccurrenceStatus::class,
            'price_override' => 'array',
            'capacity' => 'integer',
            'capacity_left' => 'integer',
            'booking_enabled' => 'boolean',
            'booking_fields' => 'array',
            'booking_capacity' => 'integer',
            'booking_limit' => 'integer',
            'booking_waitlist' => 'boolean',
            'booking_opens_at' => 'datetime',
            'booking_closes_at' => 'datetime',
            'cancellation_closes_at' => 'datetime',
            'is_exception' => 'boolean',
        ];
    }
}
