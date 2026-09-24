<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventPoll extends Model
{
    /** @var list<string> */
    protected $fillable = ['token', 'user_id', 'event_id', 'note', 'closes_at', 'closed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['closes_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasMany<EventPollOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(EventPollOption::class);
    }

    /** @return HasMany<EventPollVote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(EventPollVote::class);
    }

    /** Un sondaggio scaduto non si chiude da solo nel database: si legge come chiuso. */
    public function isOpen(): bool
    {
        return $this->closed_at === null && $this->closes_at->isFuture();
    }
}
