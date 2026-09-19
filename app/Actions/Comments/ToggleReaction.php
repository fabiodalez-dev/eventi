<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Enums\EventCommentReactionType;
use App\Models\EventComment;
use App\Models\EventCommentReaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mette, cambia o toglie la reazione di una persona a un commento.
 *
 * ## Le tre mosse, in una sola operazione
 *
 * | Stato prima | Si preme | Dopo |
 * |---|---|---|
 * | niente | cuore | cuore |
 * | cuore | cuore | niente (si ritira) |
 * | cuore | lampadina | **lampadina**, il cuore sparisce |
 *
 * La terza riga è la richiesta «le reazioni devono essere alternative fra
 * loro», e qui non costa un `delete` esplicito: l'indice unico su
 * `(event_comment_id, user_id)` fa sì che esista **una riga sola** per
 * persona, quindi cambiare reazione è aggiornarne il tipo.
 *
 * ## Il conteggio
 *
 * Il conteggio viene letto dalla relazione: nessuna copia denormalizzata
 * rimane obsoleta quando un account o una reazione viene cancellata.
 * Il lock serializza i toggle sullo stesso commento.
 *
 * @return array{stato: 'aggiunta'|'cambiata'|'ritirata', tipo: ?EventCommentReactionType, conteggio: int}
 */
final class ToggleReaction
{
    public function __construct(private readonly NotifyCommentActivity $avvisi) {}

    /** @return array{stato: string, tipo: ?EventCommentReactionType, conteggio: int} */
    public function handle(EventComment $comment, User $user, EventCommentReactionType $type): array
    {
        return DB::transaction(function () use ($comment, $user, $type): array {
            /*
             * `lockForUpdate` sul commento e non sulla reazione: due click
             * ravvicinati della stessa persona devono mettersi in fila, e la
             * riga della reazione potrebbe non esistere ancora.
             */
            $bloccato = EventComment::query()->lockForUpdate()->findOrFail($comment->id);
            abort_unless($bloccato->isPubliclyVisible(), 404);

            $esistente = EventCommentReaction::query()
                ->where('event_comment_id', $bloccato->id)
                ->where('user_id', $user->id)
                ->first();

            if ($esistente === null) {
                /*
                 * `forceFill` e non `create`: i modelli di questo progetto
                 * dichiarano `$guarded = ['*']`, cioè nessun campo passa per
                 * mass assignment. È la stessa forma di `CatalogReviews::submit`.
                 */
                (new EventCommentReaction)->forceFill([
                    'event_comment_id' => $bloccato->id,
                    'user_id' => $user->id,
                    'type' => $type,
                ])->save();

                /*
                 * Solo qui. Cambiare reazione o ritirarla non avvisa nessuno:
                 * altrimenti un dito nervoso riempirebbe la casella di chi ha
                 * scritto il commento.
                 */
                DB::afterCommit(function () use ($bloccato, $user, $type): void {
                    $bloccato->loadMissing(['user', 'event']);
                    $this->avvisi->reazione($bloccato, $user, $type);
                });

                return ['stato' => 'aggiunta', 'tipo' => $type, 'conteggio' => $bloccato->reactions_count];
            }

            if ($esistente->type === $type) {
                $esistente->delete();

                return ['stato' => 'ritirata', 'tipo' => null, 'conteggio' => $bloccato->reactions_count];
            }

            /* Cambio di reazione: una riga sola, cambia il tipo. */
            $esistente->forceFill(['type' => $type])->save();

            return ['stato' => 'cambiata', 'tipo' => $type, 'conteggio' => $bloccato->reactions_count];
        });
    }
}
