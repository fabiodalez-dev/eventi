<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventCommentReactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La reazione di una persona a un commento.
 *
 * Una riga per persona per commento, garantita dall'indice unico: è lì che
 * vive l'esclusività fra reazioni, non in un `if` che qualcuno può dimenticare.
 */
class EventCommentReaction extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['type' => EventCommentReactionType::class];
    }

    /** @return BelongsTo<EventComment, $this> */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(EventComment::class, 'event_comment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
