<?php

declare(strict_types=1);

namespace App\Actions\Moderation;

use App\Models\EventComment;
use App\Models\Report;
use App\Models\User;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;

/**
 * «Elimina» di una segnalazione o di un commento dalla redazione: la riga
 * sparisce davvero (niente cestino), e per un commento con lei spariscono le
 * risposte (`parent_id` in cascata). Prima non restava traccia di chi
 * l'aveva fatto né di cosa c'era: ora una riga nel registro di sicurezza
 * (`segnalazione_eliminata`, `commento_eliminato`) con l'id, l'autore e chi
 * ha eliminato. Il testo non si copia nel registro: sono dati di chi
 * scrive, e chi elimina li toglie proprio per questo.
 *
 * La chiamano il pannello (`EditReport`, `EventCommentResource`) e l'app
 * della redazione; il permesso (`delete` della policy) lo controlla chi
 * chiama.
 */
final class DeleteModeratedContent
{
    public function report(Report $report, ?User $actor): void
    {
        DB::transaction(function () use ($report, $actor): void {
            SecurityLog::scrivi('segnalazione_eliminata', $report->reporter_user_id === null ? null : User::withTrashed()->find($report->reporter_user_id), [
                'report_id' => (int) $report->getKey(),
                'autore_id' => $report->reporter_user_id === null ? null : (int) $report->reporter_user_id,
                'oggetto' => class_basename((string) $report->reportable_type),
                'oggetto_id' => (int) $report->reportable_id,
                'motivo' => $report->reason->value,
                'stato' => $report->status->value,
            ], causa: $actor);

            $report->delete();
        });
    }

    public function comment(EventComment $comment, ?User $actor): void
    {
        DB::transaction(function () use ($comment, $actor): void {
            SecurityLog::scrivi('commento_eliminato', User::withTrashed()->find($comment->user_id), [
                'comment_id' => (int) $comment->getKey(),
                'autore_id' => (int) $comment->user_id,
                'event_id' => (int) $comment->event_id,
                'parent_id' => $comment->parent_id === null ? null : (int) $comment->parent_id,
                'risposte' => $comment->replies()->count(),
            ], causa: $actor);

            $comment->delete();
        });
    }
}
