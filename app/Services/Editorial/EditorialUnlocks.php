<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\EditorialUnlock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
     * Password sbagliate di fila allo sblocco dopo cui il token si revoca.
     * Il limite di frequenza della rotta (6 ogni 15 minuti) lascia comunque
     * quasi seicento prove al giorno a chi ha un token rubato: questo è il
     * tetto vero.
     */
    public const LOCKOUT_FAILURES = 10;

    /** La finestra in cui si contano gli errori. */
    public const LOCKOUT_HOURS = 24;

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

    /**
     * La regola di `RequireRecentPassword`, per le azioni che diventano
     * pericolose solo con certi dati (dare a qualcuno il ruolo di referente
     * di un locale): 423 `EDITORIAL_LOCKED` senza sblocco, 423
     * `PASSWORD_REQUIRED` senza password negli ultimi 15 minuti.
     *
     * @throws ApiException
     */
    public function requirePassword(Request $request): void
    {
        $unlock = $this->find($request);
        if (! $this->unlocked($unlock)) {
            throw new ApiException(ApiErrorCode::EditorialLocked);
        }
        if (! $this->passwordConfirmed($unlock)) {
            throw new ApiException(ApiErrorCode::PasswordRequired);
        }
    }

    /**
     * Conta una password sbagliata allo sblocco; vero quando gli errori di
     * questa persona nelle ultime 24 ore arrivano a `LOCKOUT_FAILURES`. Il
     * conto è per persona e non per token: un token revocato non si
     * sostituisce con un altro per ricominciare da zero.
     */
    public function recordFailure(User $user): bool
    {
        $key = self::failuresKey($user);
        RateLimiter::hit($key, self::LOCKOUT_HOURS * 3600);

        return RateLimiter::attempts($key) >= self::LOCKOUT_FAILURES;
    }

    /** Quanti errori ha accumulato la persona nella finestra. */
    public function failures(User $user): int
    {
        return (int) RateLimiter::attempts(self::failuresKey($user));
    }

    /** Uno sblocco riuscito, o un token appena revocato, azzerano il conto. */
    public function clearFailures(User $user): void
    {
        RateLimiter::clear(self::failuresKey($user));
    }

    /** Revoca il token (lo sblocco cade con lui, `cascadeOnDelete`). */
    public function revokeToken(int $tokenId): void
    {
        PersonalAccessToken::query()->whereKey($tokenId)->delete();
    }

    private static function failuresKey(User $user): string
    {
        return 'editorial-unlock-failures:'.$user->getKey();
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
