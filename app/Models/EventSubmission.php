<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubmissionStatus;
use Database\Factories\EventSubmissionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventSubmission extends Model
{
    /** @use HasFactory<EventSubmissionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'city_id',
        'venue_id',
        'event_id',
        'title',
        'raw_text',
        'poster',
        'venue_hint',
        'starts_at_hint',
        'contact_name',
        'contact_email',
        'status',
        'reviewed_by',
        'reviewed_at',
        'notes',
        'ip_address',
    ];

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return BelongsTo<Venue, $this> */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @param  Builder<EventSubmission>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', SubmissionStatus::Pending);
    }

    /**
     * @param  Builder<EventSubmission>  $query
     */
    public function scopeOfStatus(Builder $query, SubmissionStatus $status): void
    {
        $query->where('status', $status);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubmissionStatus::class,
            'starts_at_hint' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }
}
