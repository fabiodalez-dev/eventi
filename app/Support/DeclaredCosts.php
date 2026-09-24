<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\EventOccurrence;

final class DeclaredCosts
{
    public const FIELDS = ['admission', 'drink', 'membership', 'other'];

    /** @return array{items: list<array{label: string, cents: int}>, total_cents: int, complete: bool, currency: string}|null */
    public static function for(?EventOccurrence $date): ?array
    {
        $items = [];
        foreach (self::FIELDS as $key) {
            $value = $date?->cost_breakdown[$key] ?? null;
            if ($value !== null && $value !== '' && is_numeric($value) && (float) $value >= 0) {
                $items[] = ['label' => __('decision.'.$key), 'cents' => (int) round((float) $value * 100)];
            }
        }

        return $items === [] ? null : ['items' => $items, 'total_cents' => array_sum(array_column($items, 'cents')), 'complete' => count($items) === count(self::FIELDS), 'currency' => $date?->event?->currency ?: 'EUR'];
    }
}
