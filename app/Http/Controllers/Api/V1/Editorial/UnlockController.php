<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Enums\ApiErrorCode;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\EditorialUnlockLockout;
use App\Services\Editorial\EditorialUnlocks;
use App\Support\Editorial\EditorialDesk;
use App\Support\SecurityLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Lo sblocco della redazione con la password dell'account (decisioni 2-4).
 *
 * Una password giusta sblocca la redazione su questo token per 12 ore e
 * conferma le azioni pericolose per 15 minuti; ogni tentativo, giusto o
 * sbagliato, va nel registro di sicurezza con `canale: app`. Il limite di
 * frequenza è stretto (`editorial-unlock`, 6 ogni 15 minuti per persona):
 * è un indirizzo che prova password.
 *
 * Il limite di frequenza da solo lascia provare quasi seicento password al
 * giorno a chi ha in mano un token rubato. Così, alla decima password
 * sbagliata in 24 ore (`EditorialUnlocks::LOCKOUT_FAILURES`), il token si
 * revoca (401: l'app esce dall'account), il registro scrive
 * `redazione_token_revocato` e partono due email, alla persona e ai super
 * admin.
 */
final class UnlockController extends Controller
{
    /**
     * Sblocca la redazione con la password dell'account.
     *
     * Corpo: `{"password": "…"}`. Risponde con lo stesso oggetto `unlock` di
     * `GET desk`. Password sbagliata: 422 su `error.fields.password`. Senza un
     * token dell'app (un accesso dal sito): 423 `EDITORIAL_LOCKED`, perché lo
     * sblocco si lega al token.
     */
    public function __invoke(Request $request, EditorialUnlocks $unlocks): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $desk = EditorialDesk::require();

        $password = $request->input('password');
        if (! is_string($password) || $password === '' || mb_strlen($password) > 255) {
            throw new ApiException(ApiErrorCode::ValidationFailed, fields: ['password' => [__('validation.required', ['attribute' => 'password'])]]);
        }

        $tokenId = $unlocks->tokenId($request);
        if ($tokenId === null) {
            throw new ApiException(ApiErrorCode::EditorialLocked);
        }

        // Un account senza password (entrato solo con il collegamento) non sblocca: la imposta prima dal sito.
        $stored = $user->getAuthPassword();
        if ($stored === '' || ! Hash::check($password, $stored)) {
            SecurityLog::scrivi('redazione_sblocco_fallito', $user, ['canale' => 'app', 'token_id' => $tokenId, 'ruolo' => $desk->role->value], causa: $user);
            if ($unlocks->recordFailure($user)) {
                $this->lockOut($request, $user, $tokenId, $unlocks);
            }

            throw new ApiException(ApiErrorCode::ValidationFailed, fields: ['password' => [__('validation.current_password')]]);
        }

        $unlocks->clearFailures($user);
        $unlock = $unlocks->unlock($user, $tokenId);
        SecurityLog::scrivi('redazione_sbloccata', $user, ['canale' => 'app', 'token_id' => $tokenId, 'ruolo' => $desk->role->value], causa: $user);

        return response()->json(['data' => $unlocks->state($unlock)]);
    }

    /**
     * Troppe password sbagliate: via il token, una riga nel registro, le due
     * email. Un server di posta che non risponde non salva il token: la
     * revoca è già fatta, l'errore va nei log.
     *
     * @throws ApiException sempre: 401, il token non c'è più
     */
    private function lockOut(Request $request, User $user, int $tokenId, EditorialUnlocks $unlocks): never
    {
        $failures = $unlocks->failures($user);
        $unlocks->revokeToken($tokenId);
        $unlocks->clearFailures($user);
        SecurityLog::scrivi('redazione_token_revocato', $user, [
            'canale' => 'app',
            'token_id' => $tokenId,
            'tentativi' => $failures,
            'ore' => EditorialUnlocks::LOCKOUT_HOURS,
        ], causa: $user);

        $notice = new EditorialUnlockLockout($user, $failures, $request->ip());
        try {
            $user->notify($notice);
            $admins = $this->superAdmins()->reject(fn (User $admin): bool => $admin->is($user));
            if ($admins->isNotEmpty()) {
                Notification::send($admins, $notice);
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        throw new ApiException(ApiErrorCode::Unauthenticated);
    }

    /** @return Collection<int, User> */
    private function superAdmins(): Collection
    {
        /* Senza il ruolo a database (un'installazione nuova) non c'è nessuno da avvisare. */
        return rescue(static fn (): Collection => User::role(UserRole::SuperAdmin->value)->get(), new Collection, false);
    }
}
