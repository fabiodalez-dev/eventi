<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Venue;
use Tiptap\Editor;

final class BeforeGoingDefaults
{
    public const FIELDS = ['membership', 'membership_notes', 'accessibility', 'accessibility_notes', 'feature_ids', 'practical_custom'];

    /** @return array<string, mixed> */
    public static function forVenue(?Venue $venue): array
    {
        return array_intersect_key($venue?->getAttribute('content_details') ?? [], array_flip([
            ...self::FIELDS, 'parking_type', 'parking_notes', 'transit_notes', 'entrance_notes',
        ]));
    }

    /** @param array<string, mixed> $defaults
     * @param  array<string, mixed>  $own
     * @return array<string, mixed>
     */
    public static function merge(array $defaults, array $own): array
    {
        foreach (['feature_ids', 'practical_custom'] as $field) {
            $defaults[$field] = is_array($defaults[$field] ?? null) ? $defaults[$field] : [];
            $own[$field] = is_array($own[$field] ?? null) ? $own[$field] : [];
        }
        $merged = array_replace($defaults, array_filter($own, fn ($value): bool => $value !== null && $value !== ''));
        $merged['feature_ids'] = array_values(array_unique(array_map('intval', [...($defaults['feature_ids']), ...($own['feature_ids'])])));
        $merged['practical_custom'] = [...array_values($defaults['practical_custom']), ...self::override('practical_custom', $own['practical_custom'], $defaults['practical_custom'])];

        return $merged;
    }

    public static function override(string $field, mixed $state, mixed $default): mixed
    {
        $state = EditorContent::clean($field, $state, 'content_details');
        $default = EditorContent::clean($field, $default, 'content_details');
        if (in_array($field, ['feature_ids', 'practical_custom'], true)) {
            $state = is_array($state) ? $state : [];
            $default = is_array($default) ? $default : [];
        }
        if ($field === 'feature_ids') {
            return array_values(array_diff(array_map('intval', $state ?? []), array_map('intval', $default ?? [])));
        }
        if ($field === 'practical_custom') {
            return array_values(array_filter($state ?? [], fn ($item): bool => ! in_array(self::comparable($item), array_map(self::comparable(...), $default ?? []), true)));
        }

        return self::comparable($state) === self::comparable($default) ? null : $state;
    }

    private static function comparable(mixed $value): mixed
    {
        if (is_array($value)) {
            // RichEditor adds paragraph markup even when the text was not edited.
            return array_map(self::comparable(...), $value);
        }
        if (is_string($value)) {
            $html = (string) Description::render($value);

            return $html === '' ? '' : (new Editor)->setContent($html)->getHTML();
        }

        return $value;
    }
}
