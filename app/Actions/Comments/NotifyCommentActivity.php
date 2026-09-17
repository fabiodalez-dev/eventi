<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Enums\EventCommentReactionType;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\EventComment;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;

/**
 * Chi va avvisato quando succede qualcosa a un commento.
 *
 * ## Le due regole che evitano il fastidio
 *
 * 1. **Mai notificare sé stessi.** Chi risponde al proprio commento o reagisce
 *    al proprio non riceve niente. Sembra ovvio, e invece è la prima cosa che
 *    manca quando la notifica la si aggancia a un evento del modello.
 * 2. **Un «mi piace» tolto e rimesso non rinotifica.** La chiave persistente
 *    in scheduled_notifications sopravvive al ritiro della reazione.
 *
 * Lo staff del locale e dell'organizzatore riceve l'avviso dei commenti nuovi
 * sui propri eventi, che è la parte «con notifica» dell'area di backend.
 */
final class NotifyCommentActivity
{
    public function risposta(EventComment $risposta, User $autoreRisposta): void
    {
        $padre = $risposta->replyTo ?? $risposta->parent;

        if ($padre === null || $padre->user_id === $autoreRisposta->id) {
            return;
        }

        $destinatario = $padre->user;

        if ($destinatario instanceof User) {
            $this->enqueue($destinatario, NotificationType::CommentReply, $risposta, $autoreRisposta);
        }
    }

    public function reazione(EventComment $commento, User $autore, EventCommentReactionType $tipo): void
    {
        if ($commento->user_id === $autore->id) {
            return;
        }

        $destinatario = $commento->user;

        if ($destinatario instanceof User) {
            $this->enqueue($destinatario, NotificationType::CommentReaction, $commento, $autore, $tipo);
        }
    }

    public function moderazione(EventComment $commento): void
    {
        $destinatario = $commento->user;

        if ($destinatario instanceof User) {
            $this->enqueue($destinatario, NotificationType::CommentModerated, $commento);
        }
    }

    /**
     * Lo staff del locale e dell'organizzatore dell'evento commentato.
     *
     * Chi ha scritto il commento non riceve il proprio avviso nemmeno se fa
     * parte dello staff: sarebbe una notifica su un gesto suo.
     */
    public function staff(EventComment $commento, User $autore): void
    {
        $evento = $commento->event;

        if ($evento === null) {
            return;
        }

        $destinatari = collect();

        if ($evento->venue !== null) {
            $destinatari = $destinatari->merge($evento->venue->members()->get());
        }

        if ($evento->organizer !== null) {
            $destinatari = $destinatari->merge($evento->organizer->users()->get());
            if ($evento->organizer->owner !== null) {
                $destinatari->push($evento->organizer->owner);
            }
        }

        $destinatari
            ->unique('id')
            ->reject(fn (User $u): bool => $u->id === $autore->id)
            ->each(fn (User $u) => $this->enqueue($u, NotificationType::EventNewComment, $commento, $autore));
    }

    private function enqueue(User $recipient, NotificationType $type, EventComment $comment, ?User $actor = null, ?EventCommentReactionType $reaction = null): void
    {
        // A withdrawn reaction must not erase the delivery's deduplication key.
        $key = implode(':', [$type->value, $comment->id, $recipient->id,
            $type === NotificationType::CommentModerated ? $comment->revision : ($actor->id ?? 0)]);
        $row = ScheduledNotification::query()->firstOrCreate(['dedupe_key' => $key], [
            'user_id' => $recipient->id,
            'notifiable_type' => $comment->getMorphClass(),
            'notifiable_id' => $comment->id,
            'type' => $type->value,
            'channel' => NotificationChannel::Mail,
            'status' => NotificationStatus::Pending,
            'send_at' => now(),
            'payload' => ['actor_id' => $actor?->id, 'reaction' => $reaction?->value],
        ]);
        if ($row->wasRecentlyCreated) {
            app(NotificationDispatcher::class)->dispatchOne($row->id);
        }
    }
}
