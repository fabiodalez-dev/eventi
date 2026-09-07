<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Enums\FollowableType;
use App\Models\Category;
use App\Models\City;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Support\Facades\DB;

final class NotificationInterests
{
    /** @return array<string, list<array{id: int, name: string, selected: bool}>> */
    public function options(User $user, City $city): array
    {
        $follows = $user->follows()->get();

        return [
            'categories' => Category::query()->active()->ordered()->get(['id', 'name'])->map(fn ($item) => ['id' => $item->id, 'name' => $item->name, 'selected' => $follows->where('followable_type', FollowableType::Category->value)->contains('followable_id', $item->id)])->all(),
            'venues' => Venue::query()->approved()->where('city_id', $city->id)->orderBy('name')->get(['id', 'name'])->map(fn ($item) => ['id' => $item->id, 'name' => $item->name, 'selected' => $follows->where('followable_type', FollowableType::Venue->value)->contains('followable_id', $item->id)])->all(),
        ];
    }

    /** @param array{categories?: list<int>, venues?: list<int>} $selection */
    public function update(User $user, City $city, array $selection): void
    {
        DB::transaction(function () use ($user, $city, $selection): void {
            $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            foreach (['categories' => FollowableType::Category, 'venues' => FollowableType::Venue] as $key => $type) {
                if (! array_key_exists($key, $selection)) {
                    continue;
                }
                $allowed = $type === FollowableType::Category
                    ? Category::query()->active()->pluck('id')
                    : Venue::query()->approved()->where('city_id', $city->id)->pluck('id');
                $ids = array_values(array_intersect($selection[$key], $allowed->all()));
                $user->follows()->where('followable_type', $type->value)->whereIn('followable_id', $allowed)->whereNotIn('followable_id', $ids)->delete();
                foreach ($ids as $id) {
                    $user->follows()->updateOrCreate(['followable_type' => $type->value, 'followable_id' => $id], ['notify' => true]);
                }
            }
        });
    }
}
