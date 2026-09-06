<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Actions\CreateSharedEventTag;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

final class SharedEventTags
{
    public static function make(): Select
    {
        return Select::make('tags')
            ->label(__('admin.fields.tags'))
            ->helperText(__('tags.help'))
            ->relationship('tags', 'name')
            ->multiple()->searchable()->preload()
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
