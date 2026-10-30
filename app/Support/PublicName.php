<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ProfileVisibility;
use App\Models\CommunityProfile;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Il nome con cui una persona compare in pubblico accanto a ciò che ha
 * scritto: commenti agli eventi, recensioni, avvisi agli altri utenti.
 *
 * ## Mai il nome dell'account intero
 *
 * `users.name` lo compone la registrazione come «nome cognome», e il cognome
 * dell'account non si propone in pubblico (docs/PROFILE-INTERACTIONS.md).
 * La scheda web dei commenti mostrava già solo il nome; l'API e le recensioni
 * di chi non ha un profilo community restituivano invece la stringa intera.
 * Un punto solo, così le superfici non si separano di nuovo.
 */
final class PublicName
{
    /**
     * Il nome proprio: `first_name`, o la prima parola di `name` per gli
     * account nati prima che il campo esistesse.
     */
    public static function first(?User $user): string
    {
        if ($user === null) {
            return __('community.member');
        }

        $first = trim((string) $user->first_name);

        if ($first === '') {
            $first = trim(Str::before(trim((string) $user->name), ' '));
        }

        return $first !== '' ? $first : __('community.member');
    }

    /**
     * Il nome pubblico del profilo community, se chi legge può vederlo;
     * altrimenti il solo nome proprio.
     *
     * Stessa regola di visibilità di `EventPolls::name()`: un profilo privato,
     * o riservato agli iscritti e letto da un anonimo, non presta il suo nome.
     */
    public static function for(?User $user, ?User $viewer): string
    {
        $profile = $user?->communityProfile;

        if ($profile instanceof CommunityProfile && filled($profile->display_name)) {
            $visibile = $profile->visibility === ProfileVisibility::Public
                || ($profile->visibility === ProfileVisibility::Members && $viewer?->hasVerifiedEmail() === true);

            if ($visibile) {
                return $profile->display_name;
            }
        }

        return self::first($user);
    }
}
