<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Enums\FollowableType;
use App\Models\User;

/**
 * Smettere di seguire.
 *
 * Le date già salvate **restano**: chi smette di seguire una rassegna sta
 * dicendo «non aggiungermene altre», non «cancella dall'agenda le serate a cui
 * volevo andare». Toglierle sarebbe una perdita silenziosa di dati.
 */
final class UnfollowSubject
{
    public function __invoke(User $user, FollowableType $type, int $id): bool
    {
        return $user->follows()
            ->where('followable_type', $type->value)
            ->where('followable_id', $id)
            ->delete() > 0;
    }
}
