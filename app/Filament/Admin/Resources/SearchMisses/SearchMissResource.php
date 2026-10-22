<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SearchMisses;

use App\Filament\Admin\Resources\SearchMisses\Pages\ManageSearchMisses;
use App\Filament\Concerns\HasSentenceCaseNavigationLabel;
use App\Models\SearchMiss;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Le ricerche senza risultati (P4 della roadmap, «analizzare ricerche senza
 * risultati»). Sola lettura, con la cancellazione: una riga tolta sparisce
 * finché qualcuno non cerca di nuovo la stessa cosa.
 */
class SearchMissResource extends Resource
{
    use HasSentenceCaseNavigationLabel;

    protected static ?string $model = SearchMiss::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlassMinus;

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'ricerche-senza-risultati';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.analytics');
    }

    public static function getModelLabel(): string
    {
        return __('admin.search_misses.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.search_misses.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('term')->label(__('admin.search_misses.term'))->searchable()->weight('bold'),
                TextColumn::make('count')->label(__('admin.search_misses.count'))->numeric()->sortable(),
                TextColumn::make('last_searched_at')->label(__('admin.search_misses.last'))->dateTime('d/m/Y H:i', 'Europe/Rome')->sortable(),
                TextColumn::make('first_searched_at')->label(__('admin.search_misses.first'))->dateTime('d/m/Y', 'Europe/Rome')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('city.name')->label(__('admin.fields.city'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('count', 'desc')
            ->filters([
                SelectFilter::make('city')->label(__('admin.fields.city'))->relationship('city', 'name'),
            ])
            ->recordActions([
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** @return array<string, mixed> */
    public static function getPages(): array
    {
        return [
            'index' => ManageSearchMisses::route('/'),
        ];
    }
}
