<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Musonza\Chat\Models\Message;
use Musonza\Chat\Models\Participation;

/**
 * Typed read model for the package-owned table; writes use Musonza's send operation.
 *
 * @property int $conversation_id
 * @property Carbon $created_at
 */
class RideMessage extends Message
{
    protected $table = 'chat_messages';

    /** @return BelongsTo<Participation, $this> */
    public function participation(): BelongsTo
    {
        return $this->belongsTo(Participation::class, 'participation_id');
    }
}
