<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\Import\ImportUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class PublicPushEndpoint implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('validation.url')->translate();

            return;
        }
        $parts = parse_url($value);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)
            || app(ImportUrlGuard::class)->reject($value) !== null) {
            $fail('validation.url')->translate();
        }
    }
}
