<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Venues\RelationManagers;

use App\Enums\EventStatus;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Support\EventStatusPresentation;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Gli eventi collegati al locale (§9.2). È una vista: si legge e si salta alla
 * scheda dell'evento, non si modifica da qui — il modulo completo, con le
 * date e le ricorrenze, è quello della risorsa Eventi, e duplicarne una parte
 * qui significherebbe avere due punti in cui si scrivono le stesse colonne.
 */
class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.resources.event.plural');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title')
                    ->label(__('admin.fields.title'))
                    ->searchable()
                    ->limit(60),

                TextColumn::make('category.name')
                    ->label(__('admin.fields.category')),

                TextColumn::make('status')
                    ->label(__('admin.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (EventStatus $state): string => $state->label())
                    ->color(fn (EventStatus $state): string => EventStatusPresentation::color($state)),

                TextColumn::make('occurrences_count')
                    ->label(__('admin.fields.occurrences_count'))
                    ->counts('occurrences'),

                TextColumn::make('published_at')
                    ->label(__('admin.fields.published_at'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder(__('admin.placeholders.never'))
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.fields.status'))
                    ->options(EventStatus::options()),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(__('common.actions.show_more'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(fn (Model $record): string => EventResource::getUrl('edit', ['record' => $record]))
                    ->authorize(fn (Model $record): bool => auth()->user()?->can('update', $record) ?? false),
            ]);
    }
}
