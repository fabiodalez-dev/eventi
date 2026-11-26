<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Models\EditorialUnlock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Lo sblocco della redazione nell'app, per token (decisioni 2 e 3).
 *
 * Il token dell'app dura 90 giorni e ha tutte le abilità: senza una seconda
 * barriera un telefono rubato e sbloccato aprirebbe la redazione per tre
 * mesi. Così invece:
 *
 * - la password dell'account (`POST editorial/unlock`) sblocca la redazione
 *   su quel token per 12 ore (`RequireEditorialUnlock`);
 * - le azioni pericolose chiedono la password scritta negli ultimi 15
 *   minuti (`RequireRecentPassword`); riscriverla rinnova anche le 12 ore.
 *
 * Il blocco del telefono (biometria o PIN) e il blocco dopo 15 minuti in
 * secondo piano li fa l'app: il server conosce solo la password.
 */
final class EditorialUnlocks
{
    /** Ore di validità di uno sblocco con la password. */
    public const UNLOCK_HOURS = 12;

    /** Minuti in cui le azioni pericolose valgono senza chiedere di nuovo la password. */
    public const PASSWORD_MINUTES = 15;

    /** Minuti in secondo piano o di inattività dopo cui l'app blocca l'area (lo fa l'app). */
    public const LOCK_AFTER_MINUTES = 15;

    /**
     * Il token dell'app con cui arriva la richiesta. `null` per un accesso
     * senza token salvato (la sessione del sito, un token di prova): la
     * redazione dell'app vale solo per i token veri, e senza resta bloccata.
     */
    public function tokenId(Request $request): ?int
    {
        $user = $request->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        return $token instanceof PersonalAccessToken && $token->exists ? (int) $token->getKey() : null;
    }

    public function find(Request $request): ?EditorialUnlock
    {
        $tokenId = $this->tokenId($request);
        $user = $request->user();
        if ($tokenId === null || ! $user instanceof User) {
            return null;
        }

        return EditorialUnlock::query()->where('token_id', $tokenId)->where('user_id', $user->getKey())->first();
    }

    /** Vero se la password è stata scritta su questo token nelle ultime 12 ore. */
    public function unlocked(?EditorialUnlock $unlock): bool
    {
        return $unlock !== null && $unlock->unlocked_at->greaterThan(CarbonImmutable::now()->subHours(self::UNLOCK_HOURS));
    }

    /** Vero se la password è stata scritta negli ultimi 15 minuti (e lo sblocco vale ancora). */
    public function passwordConfirmed(?EditorialUnlock $unlock): bool
    {
        return $this->unlocked($unlock) && $unlock !== null && $unlock->confirmed_until->isFuture();
    }

    /** Scrive lo sblocco sul token: 12 ore per l'area, 15 minuti per le azioni pericolose. */
    public function unlock(User $user, int $tokenId): EditorialUnlock
    {
        $now = CarbonImmutable::now();

        return EditorialUnlock::query()->updateOrCreate(
            ['token_id' => $tokenId],
            ['user_id' => $user->getKey(), 'unlocked_at' => $now, 'confirmed_until' => $now->addMinutes(self::PASSWORD_MINUTES)],
        );
    }

    /**
     * Lo stato da dare all'app in `GET editorial/desk` e dopo lo sblocco.
     *
     * @return array{unlocked: bool, unlocked_until: string|null, password_confirmed_until: string|null, unlock_hours: int, password_minutes: int, lock_after_minutes: int}
     */
    public function state(?EditorialUnlock $unlock): array
    {
        $unlocked = $this->unlocked($unlock);

        return [
            'unlocked' => $unlocked,
            'unlocked_until' => $unlocked && $unlock !== null ? $unlock->unlocked_at->addHours(self::UNLOCK_HOURS)->toIso8601String() : null,
            'password_confirmed_until' => $this->passwordConfirmed($unlock) && $unlock !== null ? $unlock->confirmed_until->toIso8601String() : null,
            'unlock_hours' => self::UNLOCK_HOURS,
            'password_minutes' => self::PASSWORD_MINUTES,
            'lock_after_minutes' => self::LOCK_AFTER_MINUTES,
        ];
    }
}
