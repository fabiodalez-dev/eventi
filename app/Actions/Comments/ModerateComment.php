<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Enums\EventCommentStatus;
use App\Models\EventComment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Nasconde o rimette in vista un commento.
 *
 * ## Il numero di revisione, e perché non è un vezzo
 *
 * È lo stesso meccanismo di `VenueReviews::moderate()`. Chi apre il pannello
 * vede lo stato in quel momento; se nel frattempo un altro moderatore agisce,
 * la sua decisione verrebbe sovrascritta in silenzio da chi preme per secondo.
 * Passando la revisione letta, il secondo scrive solo se nulla è cambiato — e
 * altrimenti riceve un errore invece di cancellare il lavoro altrui.
 *
 * ## La doppia autorizzazione
 *
 * `Gate::forUser()` qui dentro **oltre** al controllo dichiarativo di
 * Filament: il pannello protegge il pulsante, questa riga protegge l'azione.
 * Sono due cose diverse, e la seconda vale anche quando l'azione viene
 * chiamata da un altro punto del codice.
 */
final class ModerateComment
{
    public function __construct(private readonly NotifyCommentActivity $avvisi) {}

    public function nascondi(EventComment $comment, User $moderatore, int $revisione, ?string $nota = null): void
    {
        Gate::forUser($moderatore)->authorize('hide', $comment);

        $this->applica($comment, $moderatore, $revisione, EventCommentStatus::Hidden, $nota);
    }

    public function ripristina(EventComment $comment, User $moderatore, int $revisione): void
    {
        Gate::forUser($moderatore)->authorize('restore', $comment);

        $this->applica($comment, $moderatore, $revisione, EventCommentStatus::Published, null);
    }

    private function applica(EventComment $comment, User $moderatore, int $revisione, EventCommentStatus $stato, ?string $nota): void
    {
        DB::transaction(function () use ($comment, $moderatore, $revisione, $stato, $nota): void {
            $bloccato = EventComment::query()->lockForUpdate()->findOrFail($comment->id);

            if ($bloccato->revision !== $revisione) {
                throw ValidationException::withMessages(['revision' => __('comments.moderation.changed')]);
            }

            $bloccato->forceFill([
                'status' => $stato,
                'moderated_by' => $moderatore->id,
                'moderated_at' => now(),
                'moderation_note' => $nota,
                'revision' => $bloccato->revision + 1,
            ])->save();

            if ($stato === EventCommentStatus::Hidden) {
                DB::afterCommit(function () use ($bloccato): void {
                    $bloccato->loadMissing(['user', 'event']);
                    $this->avvisi->moderazione($bloccato);
                });
            }
        });
    }
}
