<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ImportSources\RelationManagers;

use App\Enums\ImportRunStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Lo storico delle esecuzioni di una sorgente (§14.2, «log errori»).
 *
 * È **solo lettura**, e non per pigrizia: una riga di questa tabella racconta
 * un fatto già accaduto. Poterla modificare significherebbe poter riscrivere
 * il registro che serve a capire da quando una sorgente ha smesso di portare
 * eventi, che è l'unica domanda per cui questa tabella esiste.
 */
class RunsRelationManager extends RelationManager
{
    protected static string $relationship = 'runs';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.import.history_heading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->description(__('admin.import.history_description'))
            ->emptyStateHeading(__('admin.import.history_empty'))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('admin.fields.run_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                /*
                 * Come in elenco: l'etichetta dell'enum quando il valore si
                 * riconosce, il valore grezzo quando no. La colonna è una
                 * stringa libera per contratto.
                 */
                TextColumn::make('status')
                    ->label(__('admin.fields.last_status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ImportRunStatus::tryFrom($state)?->label() ?? $state)
                    ->color(fn (string $state): string => match (ImportRunStatus::tryFrom($state)) {
                        ImportRunStatus::Success => 'success',
                        ImportRunStatus::Partial => 'warning',
                        ImportRunStatus::Failed => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('created_count')
                    ->label(__('import.report.created'))
                    ->numeric(),

                TextColumn::make('updated_count')
                    ->label(__('import.report.updated'))
                    ->numeric(),

                TextColumn::make('unchanged_count')
                    ->label(__('import.report.unchanged'))
                    ->numeric(),

                TextColumn::make('excluded_count')
                    ->label(__('import.report.excluded'))
                    ->numeric(),

                TextColumn::make('cancelled_count')
                    ->label(__('import.report.cancelled'))
                    ->numeric(),

                TextColumn::make('message')
                    ->label(__('admin.fields.last_error'))
                    ->limit(60)
                    ->color('danger')
                    ->placeholder(__('admin.placeholders.none')),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.fields.last_status'))
                    ->options(ImportRunStatus::options()),
            ]);
    }

    /**
     * Il registro si guarda se si può guardare la sorgente: non ha un permesso
     * proprio, perché non è un dato proprio.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }
}
