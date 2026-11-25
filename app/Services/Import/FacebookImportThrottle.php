<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Quante volte una persona può importare un evento da Facebook: 3 al minuto e
 * 30 al giorno, con gli stessi secchi per il pannello del locale, quello
 * dell'organizzazione e l'app (`facebook-import:{id}` e
 * `facebook-import:{id}:daily`). Ogni lettura apre un browser sul server:
 * il tetto protegge lui, non solo Facebook.
 */
final class FacebookImportThrottle
{
    public const PER_MINUTE = 3;

    public const PER_DAY = 30;

    /**
     * Consuma un tentativo. `null` se si può procedere, altrimenti il limite
     * superato: `minute_limit` o `daily_limit` (le chiavi dei messaggi in
     * `lang/it/facebook_import.php`).
     */
    public function attempt(User $user): ?string
    {
        $key = 'facebook-import:'.$user->getKey();
        foreach ([[$key, self::PER_MINUTE, 'minute_limit'], [$key.':daily', self::PER_DAY, 'daily_limit']] as [$bucket, $limit, $message]) {
            if (RateLimiter::tooManyAttempts($bucket, $limit)) {
                return $message;
            }
        }
        RateLimiter::hit($key, 60);
        RateLimiter::hit($key.':daily', 86400);

        return null;
    }
}
