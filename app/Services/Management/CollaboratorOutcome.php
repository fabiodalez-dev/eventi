<?php

declare(strict_types=1);

namespace App\Services\Management;

/**
 * Come è andato un gesto sui collaboratori del locale. La pagina del pannello
 * lo traduce in un avviso, l'API in uno stato HTTP: il motivo è lo stesso.
 */
enum CollaboratorOutcome: string
{
    case Invited = 'invited';
    case Resent = 'resent';
    case Withdrawn = 'withdrawn';
    case Removed = 'removed';
    /** Oltre i 10 inviti l'ora o i 30 al giorno (invito e «Reinvia» insieme). */
    case LimitReached = 'limit_reached';
    case AlreadyMember = 'already_member';
    case AlreadyInvited = 'already_invited';
    /** Non è un invito da collaboratore in attesa (reinvio, ritiro), o non è un collaboratore che si può togliere. */
    case NotAllowed = 'not_allowed';

    public function succeeded(): bool
    {
        return in_array($this, [self::Invited, self::Resent, self::Withdrawn, self::Removed], true);
    }
}
