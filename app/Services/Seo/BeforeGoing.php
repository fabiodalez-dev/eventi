<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Enums\AgeGroup;
use App\Models\Event;
use App\Models\EventFeature;
use App\Support\PracticalIcons;
use Illuminate\Support\Facades\Cache;

final class BeforeGoing
{
    /** @param array<string, mixed> $details
     * @return list<array{label: string, icon: string, text: string}>
     */
    public function items(Event $event, array $details): array
    {
        $catalog = collect(Cache::remember('event-features:catalog:v2', 300, fn (): array => EventFeature::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()->toArray()));
        $items = [];
        $append = function (string $label, string $icon, string $text = '') use (&$items): void {
            $items[] = ['label' => $label, 'icon' => PracticalIcons::safe($icon), 'text' => $text];
        };
        $system = function (string $slug, string $label, string $icon, string $text = '') use ($catalog, $append): void {
            $feature = $catalog->firstWhere('slug', $slug);
            $append($feature['name'] ?? $label, $feature['icon'] ?? $icon, $text);
        };
        $ages = array_filter(array_map(static fn ($value) => is_string($value) ? AgeGroup::tryFrom($value) : null, is_array($details['age_groups'] ?? null) ? $details['age_groups'] : []));
        if ($ages !== []) {
            $append(__('family.title'), 'users', implode(', ', array_map(static fn (AgeGroup $age): string => $age->label(), $ages)));
        }
        foreach (['stroller', 'changing_table', 'kids_area'] as $field) {
            if (in_array($details[$field] ?? null, ['yes', 'no'], true)) {
                $append(__('family.'.$field).': '.__('family.'.$details[$field]), 'users');
            }
        }
        if ($membership = $event->membershipRequirement()) {
            $system('membership-'.$membership->value, $membership->label(), 'identification', (string) ($details['membership_notes'] ?? ''));
        } elseif (filled($details['membership_notes'] ?? null)) {
            $append('Informazioni sulla tessera', 'identification', $details['membership_notes']);
        }
        if (in_array($details['accessibility'] ?? null, ['yes', 'no'], true)) {
            $system('accessibility-'.$details['accessibility'], $details['accessibility'] === 'yes' ? 'Accessibile in sedia a rotelle' : 'Non accessibile in sedia a rotelle', 'hand-raised', (string) ($details['accessibility_notes'] ?? ''));
        } elseif (filled($details['accessibility_notes'] ?? null)) {
            $append('Accessibilità', 'hand-raised', $details['accessibility_notes']);
        }
        $ids = array_map('intval', is_array($details['feature_ids'] ?? null) ? $details['feature_ids'] : []);
        foreach ($catalog as $feature) {
            if (! $feature['is_system'] && in_array($feature['id'], $ids, true)) {
                $append($feature['name'], $feature['icon'], $feature['description'] ?? '');
            }
        }
        if (isset($details['minimum_age'])) {
            $append('Età minima: '.$details['minimum_age'].' anni', 'users');
        }
        if (in_array($details['parking_type'] ?? null, ['free', 'paid', 'none'], true)) {
            $append(__('seo.parking_'.$details['parking_type']), 'map-pin');
        }
        foreach (['parking_notes' => 'map-pin', 'transit_notes' => 'truck', 'entrance_notes' => 'arrow-right-on-rectangle', 'mandatory_costs' => 'currency-euro', 'weather_policy' => 'cloud', 'minors_policy' => 'users', 'cancellation_policy' => 'arrow-path', 'refund_policy' => 'arrow-path', 'public_contact' => 'chat-bubble-left-right'] as $field => $icon) {
            if (filled($details[$field] ?? null)) {
                $append(__('seo.fields.'.$field), $icon, $details[$field]);
            }
        }
        foreach (is_array($details['practical_custom'] ?? null) ? $details['practical_custom'] : [] as $custom) {
            if (is_array($custom) && is_string($custom['label'] ?? null) && filled($custom['label'])) {
                $append($custom['label'], PracticalIcons::safe(is_string($custom['icon'] ?? null) ? $custom['icon'] : null), is_string($custom['text'] ?? null) ? $custom['text'] : '');
            }
        }

        // Venue facilities remain venue data, presented once alongside event advice.
        foreach ($event->venue?->accessibility?->available() ?? [] as $facility) {
            $label = $facility->label();
            if (! in_array($label, array_column($items, 'label'), true)) {
                $append($label, 'check-circle', 'Disponibile nel locale');
            }
        }

        return $items;
    }
}
