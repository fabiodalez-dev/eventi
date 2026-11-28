<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Il tetto delle anteprime di import della redazione, per il pannello e per
 * l'app insieme (gli stessi secchi).
 *
 * Un'anteprima legge un calendario esterno dentro la richiesta: fino a 15
 * secondi di un processo PHP (`ImportRunner::preview`). Su un hosting
 * condiviso i processi sono pochi, e il limite per persona da solo non basta:
 * due account bastano a occuparli tutti. Per questo, oltre al tetto per
 * persona, ce n'è uno per tutto il sito.
 */
final class ImportPreviewLimiter
{
    public const GLOBAL_KEY = 'import-preview:global';

    /** Anteprime al minuto per tutto il sito: con 15 secondi l'una, al più tre processi occupati insieme. */
    public const GLOBAL_PER_MINUTE = 12;

    /** Anteprime al minuto per persona, come il limite della rotta dell'app. */
    public const PERSON_PER_MINUTE = 10;

    private const DECAY_SECONDS = 60;

    /** Vero se l'anteprima può partire, e in quel caso la conta. */
    public static function attempt(?User $user): bool
    {
        $person = self::personKey($user);
        if (RateLimiter::tooManyAttempts(self::GLOBAL_KEY, self::GLOBAL_PER_MINUTE)
            || ($person !== null && RateLimiter::tooManyAttempts($person, self::PERSON_PER_MINUTE))) {
            return false;
        }

        RateLimiter::hit(self::GLOBAL_KEY, self::DECAY_SECONDS);
        if ($person !== null) {
            RateLimiter::hit($person, self::DECAY_SECONDS);
        }

        return true;
    }

    private static function personKey(?User $user): ?string
    {
        return $user === null ? null : 'import-preview:user:'.$user->getKey();
    }
}
