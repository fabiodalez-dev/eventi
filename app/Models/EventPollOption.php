<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventPollOption extends Model
{
    /** @var list<string> */
    protected $fillable = ['event_poll_id', 'occurrence_id'];

    /** @return BelongsTo<EventPoll, $this> */
    public function poll(): BelongsTo
    {
        return $this->belongsTo(EventPoll::class, 'event_poll_id');
    }

    /** @return BelongsTo<EventOccurrence, $this> */
    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(EventOccurrence::class, 'occurrence_id');
    }

    /** @return HasMany<EventPollVote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(EventPollVote::class);
    }
}
