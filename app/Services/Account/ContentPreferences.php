<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Enums\AccessibilityFeature;
use App\Models\Category;
use App\Models\User;

/** Explicit discovery choices, separate from notification subscriptions and purchases. */
final class ContentPreferences
{
    /** @return array{mode: string, categories: list<int>, hidden_categories: list<int>, inferred_ads: bool, hidden_venues: list<int>, access_needs: list<string>} */
    public function selection(?User $user): array
    {
        $data = $user->content_preferences ?? [];

        return [
            'mode' => ($data['mode'] ?? 'all') === 'selected' ? 'selected' : 'all',
            'categories' => array_map('intval', $data['categories'] ?? []),
            'hidden_categories' => array_map('intval', $data['hidden_categories'] ?? []),
            'inferred_ads' => (bool) ($data['inferred_ads'] ?? true),
            'hidden_venues' => array_map('intval', $data['hidden_venues'] ?? []),
            'access_needs' => array_values(array_filter(array_map('strval', $data['access_needs'] ?? []),
                static fn (string $need): bool => AccessibilityFeature::tryFrom($need) !== null)),
        ];
    }

    /**
     * I locali che la persona ha chiesto di non vedere: spariscono dalle
     * stesse viste delle categorie nascoste, mai dai salvati o dai biglietti.
     *
     * @return list<int>
     */
    public function hiddenVenues(?User $user): array
    {
        return $user === null ? [] : $this->selection($user)['hidden_venues'];
    }

    /**
     * Aggiunge o toglie un locale dai nascosti, conservando il resto delle
     * preferenze.
     */
    public function toggleVenue(User $user, int $venueId, bool $hide): void
    {
        $data = $user->content_preferences ?? [];
        $hidden = array_map('intval', $data['hidden_venues'] ?? []);
        $hidden = $hide ? array_values(array_unique([...$hidden, $venueId])) : array_values(array_diff($hidden, [$venueId]));
        $data['hidden_venues'] = array_slice($hidden, 0, 500);
        $user->update(['content_preferences' => $data]);
    }

    /** @return list<int> */
    public function hidden(?User $user): array
    {
        $data = $this->selection($user);
        if ($data['mode'] === 'selected') {
            return Category::query()->whereNotIn('id', $data['categories'])->pluck('id')->map(fn ($id): int => (int) $id)->all();
        }

        return $data['hidden_categories'];
    }

    /** Never apply consumer preferences to back-office, jobs, tickets or saved-event exports. */
    public function discoveryUser(): ?User
    {
        $request = app('request');
        if ($request->attributes->get('personalize_discovery') !== true) {
            return null;
        }
        $user = $request->is('api/*') ? $request->user('sanctum') : $request->user();

        return $user instanceof User ? $user : null;
    }
}
