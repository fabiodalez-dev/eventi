<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Venues\RelationManagers;

use App\Enums\EventStatus;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Support\EventStatusPresentation;
use App\Filament\Support\PosterColumn;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Gli eventi collegati al locale (§9.2), con la locandina. È una vista: si
 * legge e si salta alla scheda dell'evento, non si modifica da qui — il modulo completo, con le
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['media', 'category']))
            ->columns([
                /* Locandina e testo affiancati anche sul telefono, come
                   nell'elenco degli eventi: la tabella a sei colonne a 390 px
                   spingeva fuori schermo stato e azioni. */
                Split::make([
                    PosterColumn::make('poster_media'),
                    Stack::make([
                        TextColumn::make('title')
                            ->label(__('admin.fields.title'))
                            ->searchable()
                            ->weight(FontWeight::SemiBold)
                            ->wrap()
                            ->extraAttributes(['class' => 'py-0.5!']),

                        TextColumn::make('category.name')
                            ->label(__('admin.fields.category'))
                            ->color('gray')
                            ->size(TextSize::Small)
                            ->extraAttributes(['class' => 'py-0.5!']),

                        TextColumn::make('status')
                            ->label(__('admin.fields.status'))
                            ->badge()
                            ->formatStateUsing(fn (EventStatus $state): string => $state->label())
                            ->color(fn (EventStatus $state): string => EventStatusPresentation::color($state))
                            ->extraAttributes(['class' => 'py-0.5!']),
                    ])->space(1),
                    Stack::make([
                        TextColumn::make('occurrences_count')
                            ->label(__('admin.fields.occurrences_count'))
                            ->counts('occurrences')
                            ->size(TextSize::Small)
                            ->formatStateUsing(fn (int $state): string => trans_choice('admin.event_list.dates_count', $state, ['count' => $state]))
                            ->extraAttributes(['class' => 'py-0.5!']),

                        TextColumn::make('published_at')
                            ->label(__('admin.fields.published_at'))
                            ->size(TextSize::Small)
                            ->color('gray')
                            ->formatStateUsing(fn (CarbonInterface $state): string => __('admin.event_list.published_at', ['date' => $state->copy()->timezone(FilamentTimezone::get())->format('d/m/Y H:i')]))
                            ->placeholder(__('admin.event_list.never_published'))
                            ->sortable()
                            ->extraAttributes(['class' => 'py-0.5!']),
                    ])->space(1)->alignment(Alignment::End)->grow(false)->visibleFrom('lg'),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.fields.status'))
                    ->options(EventStatus::options()),
            ])
            /* La riga apre la scheda: la modifica a chi può modificare, la
               lettura a chi può solo moderare. */
            ->recordUrl(fn (Model $record): ?string => match (true) {
                EventResource::canEdit($record) => EventResource::getUrl('edit', ['record' => $record]),
                EventResource::canView($record) => EventResource::getUrl('view', ['record' => $record]),
                default => null,
            })
            ->recordActions([
                Action::make('open')
                    ->label(__('admin.actions.open_record'))
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->button()
                    ->url(fn (Model $record): string => EventResource::getUrl(EventResource::canEdit($record) ? 'edit' : 'view', ['record' => $record]))
                    ->authorize(fn (Model $record): bool => auth()->user()?->can('view', $record) ?? false),
            ]);
    }
}
