<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class InternalRedirectTarget implements ValidationRule
{
    public static function safe(string $value): bool
    {
        $decoded = rawurldecode($value);

        return str_starts_with($decoded, '/') && ! str_starts_with($decoded, '//')
            && ! str_contains($decoded, '\\') && preg_match('/[\x00-\x20\x7f]/', $decoded) === 0;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::safe($value)) {
            $fail('Inserisci un percorso interno che inizi con una sola barra (/).');
        }
    }
}
