<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Enums\EventCommentReactionType;
use App\Enums\NotificationType;
use App\Models\EventComment;
use App\Models\User;
use App\Notifications\CommentActivity;

/**
 * Chi va avvisato quando succede qualcosa a un commento.
 *
 * ## Le due regole che evitano il fastidio
 *
 * 1. **Mai notificare sé stessi.** Chi risponde al proprio commento o reagisce
 *    al proprio non riceve niente. Sembra ovvio, e invece è la prima cosa che
 *    manca quando la notifica la si aggancia a un evento del modello.
 * 2. **Un «mi piace» tolto e rimesso non rinotifica.** Chiama qui solo chi ha
 *    appena *aggiunto* una reazione: cambiarla o ritirarla non avvisa nessuno,
 *    o basterebbe un dito nervoso per riempire la casella di qualcuno.
 *
 * Lo staff del locale e dell'organizzatore riceve l'avviso dei commenti nuovi
 * sui propri eventi, che è la parte «con notifica» dell'area di backend.
 */
final class NotifyCommentActivity
{
    public function risposta(EventComment $risposta, User $autoreRisposta): void
    {
        $padre = $risposta->parent;

        if ($padre === null || $padre->user_id === $autoreRisposta->id) {
            return;
        }

        $destinatario = $padre->user;

        if ($destinatario instanceof User) {
            $destinatario->notify(new CommentActivity(NotificationType::CommentReply, $risposta, $autoreRisposta->name));
        }
    }

    public function reazione(EventComment $commento, User $autore, EventCommentReactionType $tipo): void
    {
        if ($commento->user_id === $autore->id) {
            return;
        }

        $destinatario = $commento->user;

        if ($destinatario instanceof User) {
            $destinatario->notify(new CommentActivity(NotificationType::CommentReaction, $commento, $autore->name, $tipo));
        }
    }

    public function moderazione(EventComment $commento): void
    {
        $destinatario = $commento->user;

        if ($destinatario instanceof User) {
            $destinatario->notify(new CommentActivity(NotificationType::CommentModerated, $commento, ''));
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
        }

        $destinatari
            ->unique('id')
            ->reject(fn (User $u): bool => $u->id === $autore->id)
            ->each(fn (User $u) => $u->notify(
                new CommentActivity(NotificationType::EventNewComment, $commento, $autore->name)
            ));
    }
}
