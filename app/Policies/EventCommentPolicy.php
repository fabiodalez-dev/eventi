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

    /** Commentare richiede un indirizzo email confermato. */
    public function create(User $user): bool
    {
        return $user->hasVerifiedEmail();
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
     */
    public function restore(User $user, EventComment $comment): bool
    {
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
