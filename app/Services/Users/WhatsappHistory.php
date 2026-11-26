<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Models\User;
use App\Models\WhatsappChallenge;
use Illuminate\Database\Eloquent\Collection;

/**
 * Lo storico delle verifiche WhatsApp di una persona (R-US-11): gli ultimi
 * trenta tentativi, dal più recente. La finestra «Storico tentativi
 * WhatsApp» del pannello e l'app leggono la stessa query.
 */
final class WhatsappHistory
{
    public const LIMIT = 30;

    /** @return Collection<int, WhatsappChallenge> */
    public static function for(User $user): Collection
    {
        return WhatsappChallenge::query()->where('user_id', $user->id)->latest()->limit(self::LIMIT)->get();
    }
}
