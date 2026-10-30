<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Enums\EventCommentStatus;
use App\Models\Event;
use App\Models\EventComment;
use App\Models\User;
use App\Services\Community\CommunityAccess;
use Illuminate\Support\Facades\DB;

/**
 * Pubblica un commento, o una risposta a un commento.
 *
 * ## L'annidamento si ferma al primo livello
 *
 * Rispondere a una risposta attacca il nuovo messaggio allo **stesso
 * capostipite**, non alla risposta. Non è una semplificazione pigra: al terzo
 * livello di rientro, su uno schermo da 390 punti, al testo restano meno di
 * duecento punti di larghezza. La conversazione si legge meglio piatta, con
 * il nome di chi si sta interpellando dentro il testo.
 */
final class PostComment
{
    public function __construct(private readonly NotifyCommentActivity $avvisi, private readonly CheckCommentContent $content, private readonly CommunityAccess $access) {}

    public function handle(Event $event, User $user, string $body, ?EventComment $inRispostaA = null): EventComment
    {
        return DB::transaction(function () use ($event, $user, $body, $inRispostaA): EventComment {
            // Serialize account writes so concurrent requests cannot bypass duplicate detection.
            $account = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            /* Ripetuto sulla riga bloccata: copre la sospensione arrivata
               fra il controllo della policy e la scrittura. */
            abort_unless($account->community_suspended_at === null && $account->social_suspended_at === null, 403);
            $this->content->handle($body, $user);
            $capostipite = null;

            if ($inRispostaA !== null) {
                /* Se si risponde a una risposta, si risale al primo livello. */
                $inRispostaA = EventComment::query()->lockForUpdate()->findOrFail($inRispostaA->id);
                abort_unless($inRispostaA->event_id === $event->id && $inRispostaA->isPubliclyVisible(), 404);
                $capostipite = $inRispostaA->parent_id ?? $inRispostaA->id;

                /*
                 * Un blocco fra due persone, in una qualunque delle due
                 * direzioni, vale anche qui: niente risposte a chi ti ha
                 * bloccato (o hai bloccato), né sotto la sua conversazione.
                 * Ogni risposta gli manderebbe un avviso.
                 */
                foreach ([$inRispostaA->user, $inRispostaA->parent?->user] as $altro) {
                    abort_if($altro instanceof User && $this->access->blocked($user, $altro), 403);
                }
            }

            $commento = new EventComment;
            $commento->forceFill([
                'event_id' => $event->id,
                'user_id' => $user->id,
                'parent_id' => $capostipite,
                'reply_to_id' => $inRispostaA?->id,
                'body' => trim($body),
                'status' => EventCommentStatus::Published,
                'revision' => 1,
            ])->save();

            /*
             * Le notifiche partono **dopo il commit**: se la transazione
             * fallisse, avremmo già avvisato qualcuno di un commento che non
             * esiste. `afterCommit` è la stessa cautela che usa
             * `CatalogReview::booted()` per invalidare la cache.
             */
            DB::afterCommit(function () use ($commento, $user): void {
                $commento->loadMissing(['parent.user', 'event.venue', 'event.organizer']);
                $this->avvisi->risposta($commento, $user);
                $this->avvisi->staff($commento, $user);
            });

            return $commento;
        });
    }
}
