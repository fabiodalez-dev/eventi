<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Actions\CreateSharedEventTag;
use App\Models\Event;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Str;

final class SharedEventTags
{
    public static function make(): Select
    {
        return Select::make('tags')
            ->label(__('admin.fields.tags'))
            ->helperText(__('tags.help'))
            ->relationship('tags', 'name')
            ->multiple()->searchable()->preload()
            ->maxItems(50)
            ->live()
            ->getSearchResultsUsing(function (string $search): array {
                $name = Str::squish(strip_tags($search));
                $results = Tag::query()->where('name', 'like', '%'.$name.'%')->limit(50)->pluck('name', 'id')->all();
                if ($name !== '' && mb_strlen($name) <= 80 && Str::slug($name) !== '' && ! Tag::query()->where('slug', Str::slug($name))->exists()) {
                    $results['create:'.$name] = __('tags.create_named', ['name' => $name]);
                }

                return $results;
            })
            ->afterStateUpdated(function (Select $component, ?array $state, ?Event $record): void {
                $selected = [];
                foreach ($state ?? [] as $value) {
                    if (is_string($value) && str_starts_with($value, 'create:')) {
                        // Never leave a temporary creation key in the relationship state.
                        $component->state(array_values(array_filter($state ?? [], fn ($item): bool => ! is_string($item) || ! str_starts_with($item, 'create:'))));
                        $user = auth()->user();
                        abort_unless($user instanceof User, 403);
                        $tenant = Filament::getTenant();
                        $value = app(CreateSharedEventTag::class)->execute($user, substr($value, 7), $record, $tenant instanceof Venue ? $tenant : null)->id;
                    }
                    $selected[] = $value;
                }
                $component->state(array_values(array_unique($selected)));
                $component->refreshSelectedOptionLabel();
            })
            ->createOptionForm([
                TextInput::make('name')->label(__('tags.name'))->required()->maxLength(80),
            ])
            ->createOptionAction(fn (Action $action): Action => $action
                ->label(__('tags.create'))->modalHeading(__('tags.create'))
                ->modalDescription(__('tags.shared'))->modalSubmitActionLabel(__('tags.add')))
            ->createOptionUsing(function (array $data, ?Event $record): int {
                $user = auth()->user();
                abort_unless($user instanceof User, 403);
                $tenant = Filament::getTenant();

                return app(CreateSharedEventTag::class)->execute($user, $data['name'], $record, $tenant instanceof Venue ? $tenant : null)->id;
            });
    }
}
