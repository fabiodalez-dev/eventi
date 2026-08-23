<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * L'utente delle rotte `/v1/me`, che a differenza di quelle pubbliche non è
 * facoltativo: senza, la rotta non sarebbe nemmeno stata raggiunta.
 *
 * Il controllo resta perché `auth:sanctum` garantisce *un* utente autenticato,
 * non che sia un `App\Models\User`: l'analisi statica ha ragione a chiederlo, e
 * il giorno in cui esistesse un secondo provider questa riga sarebbe l'unica a
 * saperlo.
 */
trait InteractsWithMe
{
    use InteractsWithApi;

    protected function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new ApiException(ApiErrorCode::Unauthenticated);
        }

        return $user;
    }
}
