<?php

declare(strict_types=1);

namespace App\Services\Sponsorship;

use App\Models\City;
use App\Models\Sponsorship;
use App\Models\User;
use App\Services\Account\ContentPreferences;
use Illuminate\Support\Collection;

/** Only explicit follows/saves and the current page context. No browsing history or guest fingerprint. */
final class BannerAffinity
{
    /** @param Collection<int, Sponsorship> $campaigns
     * @param  array<string, mixed>  $context
     * @return Collection<int, Sponsorship>
     */
    public function apply(Collection $campaigns, City $city, ?User $user, array $context): Collection
    {
        if ($campaigns->isEmpty()) {
            return $campaigns;
        }
        $follows = $user?->follows()->get(['followable_type', 'followable_id']) ?? collect();
        $venues = $follows->where('followable_type', 'venue')->pluck('followable_id')->map(fn ($id): int => (int) $id)->all();
        $categories = $follows->where('followable_type', 'category')->pluck('followable_id')->map(fn ($id): int => (int) $id)->all();
        $tags = $follows->where('followable_type', 'tag')->pluck('followable_id')->map(fn ($id): int => (int) $id)->all();
        $events = $follows->where('followable_type', 'event')->pluck('followable_id')->map(fn ($id): int => (int) $id)->all();
        $savedCategories = $user !== null ? app(CategoriePreferite::class)->dellUtente($user) : [];
        $preferences = app(ContentPreferences::class);
        $selection = $preferences->selection($user);
        $categories = array_unique([...$categories, ...$selection['categories']]);
        $hidden = $preferences->hidden($user);
        if (! $selection['inferred_ads']) {
            $savedCategories = [];
        }

        $weighted = $campaigns->reject(fn (Sponsorship $campaign): bool => in_array((int) $campaign->event?->category_id, $hidden, true))->values()->map(function (Sponsorship $campaign) use ($venues, $categories, $tags, $events, $savedCategories, $context): Sponsorship {
            $event = $campaign->event;
            $multiplier = 1;
            if ($event !== null) {
                if (in_array((int) $event->venue_id, $venues, true) || in_array((int) $event->id, $events, true)) {
                    $multiplier = 4;
                } elseif (in_array((int) $event->category_id, $categories, true) || $event->tags->pluck('id')->intersect($tags)->isNotEmpty()) {
                    $multiplier = 3;
                } elseif (in_array((int) $event->category_id, $savedCategories, true)) {
                    $multiplier = 1.5;
                }
                // Slugs are compared only with already eligible events in the requested city.
                $contextMatch = (filled($context['venue'] ?? null) && ($event->venue->slug ?? null) === $context['venue'])
                    || (filled($context['category'] ?? null) && ($event->category->slug ?? null) === $context['category'])
                    || (filled($context['tag'] ?? null) && $event->tags->contains('slug', $context['tag']));
                if ($contextMatch) {
                    $multiplier = max($multiplier, 2);
                }
            }
            // Never persist a personalization multiplier into the commercial campaign's weight.
            $weighted = clone $campaign;
            // Integer half-units preserve x1.5 exactly in the weighted wheel.
            $weighted->weight = (int) (max(1, (int) $campaign->weight) * $multiplier * 2);

            return $weighted;
        });
        if ($weighted->every(fn (Sponsorship $campaign): bool => $campaign->weight % 2 === 0)) {
            $weighted->each(function (Sponsorship $campaign): void {
                $campaign->weight = (int) ($campaign->weight / 2);
            });
        }

        return $weighted;
    }
}
