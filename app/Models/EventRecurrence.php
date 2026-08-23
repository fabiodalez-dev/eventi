<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EventRecurrenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventRecurrence extends Model
{
    /** @use HasFactory<EventRecurrenceFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'event_id',
        'rrule',
        'until',
        'exdates',
        'generated_until',
    ];

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasMany<EventOccurrence, $this> */
    public function occurrences(): HasMany
    {
        return $this->hasMany(EventOccurrence::class, 'recurrence_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'until' => 'datetime',
            'exdates' => 'array',
            'generated_until' => 'datetime',
        ];
    }
}
