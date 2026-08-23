<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Concerns;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * L'utente collegato nell'area personale.
 *
 * Le rotte passano già da `auth`, che rimanda alla pagina di accesso chi non
 * è entrato: qui resta da dire all'analisi statica che quell'utente è un
 * `App\Models\User` e non un contratto generico. Il giorno in cui esistesse un
 * secondo provider, questa riga sarebbe l'unica a saperlo.
 */
trait InteractsWithAccount
{
    protected function accountUser(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
