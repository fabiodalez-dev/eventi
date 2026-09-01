<?php

declare(strict_types=1);

namespace App\Casts;

use App\DTOs\AccessibilityProfile;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Il cast di `venues.accessibility`: dal JSON della colonna a
 * `App\DTOs\AccessibilityProfile`, e ritorno.
 *
 * Qui la differenza con gli altri cast è che `null` in colonna significa
 * **nulla è stato dichiarato**, che non è «niente è accessibile»: un profilo
 * senza voci presenti ma con voci dichiarate assenti si scrive comunque, ed è
 * un'informazione che vale la riga.
 *
 * @implements CastsAttributes<AccessibilityProfile, AccessibilityProfile|iterable<mixed>|string|null>
 */
final class AsAccessibilityProfile implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): AccessibilityProfile
    {
        return AccessibilityProfile::fromMixed($value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $profile = AccessibilityProfile::fromMixed($value);
        $features = $profile->toArray();

        return [$key => $features === [] ? null : json_encode($features, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    }
}
