<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Models\MobileAuthChallenge;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Il collegamento di accesso dell'app (§15.2), legato con PKCE S256
 * all'installazione che l'ha chiesto: il gettone arriva nella casella, il
 * verificatore resta nel telefono, e lo scambio vuole tutti e due.
 *
 * Lo usano il collegamento di accesso, la registrazione e l'accesso con
 * password di un account ancora in sospeso. `$keepsPassword` vale solo
 * quando la stessa richiesta ha scelto o dimostrato la password: allora chi
 * scambia il gettone è anche chi conosce la password, e lo scambio non la
 * azzera (`ClaimMailbox::confermaRegistrazione()`).
 */
final class MobileMagicLink
{
    public function issue(User $user, string $codeChallenge, bool $keepsPassword = false): string
    {
        $rawToken = Str::random(64);

        /* Un secondo invio rende inutilizzabile il precedente: oltre a
           limitare la tabella, elimina l'ambiguita' di avere piu' link
           validi per lo stesso account nello stesso momento. */
        MobileAuthChallenge::query()->where('user_id', $user->getKey())->delete();

        MobileAuthChallenge::query()->create([
            'user_id' => $user->getKey(),
            'password_fingerprint' => MagicLoginLink::fingerprint($user),
            'keeps_password' => $keepsPassword,
            'code_challenge' => $codeChallenge,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => CarbonImmutable::now()->addMinutes(config()->integer('account.magic_link_minutes')),
        ]);

        return url('/app/auth/magic?token='.rawurlencode($rawToken));
    }
}
