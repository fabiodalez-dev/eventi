<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Models\Category;
use App\Models\User;

/** Explicit discovery choices, separate from notification subscriptions and purchases. */
final class ContentPreferences
{
    /** @return array{mode: string, categories: list<int>, hidden_categories: list<int>, inferred_ads: bool} */
    public function selection(?User $user): array
    {
        $data = $user->content_preferences ?? [];

        return [
            'mode' => ($data['mode'] ?? 'all') === 'selected' ? 'selected' : 'all',
            'categories' => array_map('intval', $data['categories'] ?? []),
            'hidden_categories' => array_map('intval', $data['hidden_categories'] ?? []),
            'inferred_ads' => (bool) ($data['inferred_ads'] ?? true),
        ];
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
