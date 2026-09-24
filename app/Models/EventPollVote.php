<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventPollVote extends Model
{
    /** @var list<string> */
    protected $fillable = ['event_poll_id', 'event_poll_option_id', 'user_id'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<EventPollOption, $this> */
    public function option(): BelongsTo
    {
        return $this->belongsTo(EventPollOption::class, 'event_poll_option_id');
    }
}
