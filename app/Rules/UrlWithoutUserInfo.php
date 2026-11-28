<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Un indirizzo di import senza `utente:password@`. Le credenziali di una
 * sorgente hanno il loro campo cifrato (si scrive dal sito e non si rilegge);
 * scritte nell'indirizzo finirebbero in chiaro nel database, nelle risposte e
 * nei messaggi d'errore che lo citano.
 */
final class UrlWithoutUserInfo implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $parts = parse_url(trim($value));
        if (is_array($parts) && (isset($parts['user']) || isset($parts['pass']))) {
            $fail(__('admin.import.url_userinfo'));
        }
    }
}
