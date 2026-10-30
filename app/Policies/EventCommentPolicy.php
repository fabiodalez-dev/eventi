<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\EventComment;
use App\Models\User;
use App\Policies\Concerns\ScopesToVenueMembership;

/**
 * Chi può fare cosa con i commenti a un evento.
 *
 * ## Nascondere e cancellare non sono la stessa cosa
 *
 * Un locale ha un interesse diretto nei commenti sui propri eventi: se una
 * critica scomoda potesse sparire senza lasciare traccia, nessuno saprebbe
 * distinguere la moderazione dalla censura.
 *
 * Perciò il locale e l'organizzatore **nascondono**: il commento esce dalla
 * vista pubblica ma resta nel pannello, con chi l'ha nascosto, quando e
 * perché. È reversibile, ed è verificabile.
 *
 * **Cancellare** — cioè far sparire la riga — resta dell'amministratore, e
 * dell'autore sul proprio commento. Sono i due casi in cui non c'è un
 * conflitto d'interessi da sorvegliare.
 */
class EventCommentPolicy
{
    use ScopesToVenueMembership;

    /**
     * Commentare richiede un indirizzo email confermato, e nessuna
     * sospensione dalla community.
     *
     * Non `canParticipateInCommunity()`: chiederebbe anche WhatsApp, che per
     * i commenti agli eventi non serve. Ma la sospensione («Tutta la
     * community» o «Solo social») è lo strumento della redazione per fermare
     * chi molesta, e le recensioni la rispettano già: senza questa riga un
     * account sospeso continuava a pubblicare e a far partire avvisi.
     */
    public function create(User $user): bool
    {
        return $user->hasVerifiedEmail() && ! $user->trashed()
            && $user->community_suspended_at === null && $user->social_suspended_at === null;
    }

    /** Reagire: stesse condizioni di chi scrive, ogni reazione avvisa l'autore. */
    public function react(User $user, EventComment $comment): bool
    {
        return $this->create($user);
    }

    /**
     * Cancellare davvero: l'autore sul proprio, l'amministratore su tutti.
     *
     * Il moderatore non compare: il suo strumento è nascondere, che lascia
     * traccia. Vedi il ragionamento in testa alla classe.
     */
    public function delete(User $user, EventComment $comment): bool
    {
        return $comment->user_id === $user->id
            || $user->hasAnyRole([UserRole::Admin->value, UserRole::SuperAdmin->value]);
    }

    /**
     * Nascondere: staff globale, il locale dell'evento, il suo organizzatore.
     */
    public function hide(User $user, EventComment $comment): bool
    {
        if ($this->isGlobalStaff($user)) {
            return true;
        }

        $event = $comment->event;

        if ($event === null) {
            return false;
        }

        if ($event->organizer?->managedBy($user) === true) {
            return true;
        }

        return $event->venue_id !== null && $this->isMemberOfVenue($user, $event->venue_id);
    }

    /**
     * Rimettere in vista quello che si è nascosto.
     *
     * Stesse persone: chi può togliere deve poter rimettere, o «nascondere»
     * diventa irreversibile nei fatti anche quando non lo è nello schema.
     *
     * Con un limite: quello che ha nascosto la redazione si rimette solo da
     * `/admin`. Il locale ha un interesse diretto nei commenti sui propri
     * eventi, e non può annullare una decisione presa su una segnalazione
     * (insulti, dati personali, spam).
     */
    public function restore(User $user, EventComment $comment): bool
    {
        if ($this->isGlobalStaff($user)) {
            return true;
        }

        $moderatore = $comment->moderator;

        if ($moderatore instanceof User && $this->isGlobalStaff($moderatore)) {
            return false;
        }

        return $this->hide($user, $comment);
    }

    /**
     * Vedere anche i commenti nascosti, nei pannelli.
     */
    public function viewAny(User $user): bool
    {
        return $this->isGlobalStaff($user) || $user->venues()->exists() || $user->managedOrganizers()->exists();
    }
}
