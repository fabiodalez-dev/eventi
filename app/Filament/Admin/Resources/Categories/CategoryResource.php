<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Categories;

use App\Filament\Admin\Resources\Categories\Pages\CreateCategory;
use App\Filament\Admin\Resources\Categories\Pages\EditCategory;
use App\Filament\Admin\Resources\Categories\Pages\ListCategories;
use App\Models\Category;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * §9.2 e §7.4 — la categoria. Tre dei suoi campi non sono etichette ma
 * **regole del motore temporale**, e il pannello lo dice a chi li compila:
 *
 * - `default_duration_minutes` decide `effective_ends_at` quando l'ora di fine
 *   non è stata scritta (§8.3);
 * - `supports_ongoing` decide se un evento della categoria possa comparire fra
 *   quelli "in corso adesso" (§8.4);
 * - `is_nightlife` decide se una data dopo la mezzanotte appartenga alla sera
 *   precedente (§8.2, `business_date`).
 *
 * Cambiarli non è un ritocco estetico: l'observer ricalcola le colonne
 * persistite delle occorrenze già salvate.
 */
class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.content');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.category.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.category.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)
            ->components([
                Section::make(__('admin.sections.general'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('admin.fields.name'))
                            ->required()
                            ->maxLength(255),

                        TextInput::make('slug')
                            ->label(__('admin.fields.slug'))
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),

                        Select::make('parent_id')
                            ->label(__('admin.fields.parent_category'))
                            ->relationship(
                                'parent',
                                'name',
                                fn ($query, ?Model $record) => $record === null
                                    ? $query
                                    : $query->whereKeyNot($record->getKey()),
                            )
                            ->searchable()
                            ->preload(),

                        TextInput::make('icon')
                            ->label(__('admin.fields.icon'))
                            ->maxLength(64),

                        ColorPicker::make('color')
                            ->label(__('admin.fields.color')),

                        TextInput::make('sort_order')
                            ->label(__('admin.fields.sort_order'))
                            ->required()
                            ->numeric()
                            ->default(0),

                        Toggle::make('is_active')
                            ->label(__('admin.fields.is_active'))
                            ->default(true),
                    ]),

                Section::make(__('admin.sections.temporal'))
                    ->columns(1)
                    ->schema([
                        TextInput::make('default_duration_minutes')
                            ->label(__('admin.fields.default_duration_minutes'))
                            ->helperText(__('admin.hints.default_duration_minutes'))
                            ->numeric()
                            ->minValue(5)
                            ->maxValue(10080),

                        Toggle::make('supports_ongoing')
                            ->label(__('admin.fields.supports_ongoing'))
                            ->helperText(__('admin.hints.supports_ongoing'))
                            ->default(true),

                        Toggle::make('is_nightlife')
                            ->label(__('admin.fields.is_nightlife'))
                            ->helperText(__('admin.hints.is_nightlife')),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.fields.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('parent.name')
                    ->label(__('admin.fields.parent_category'))
                    ->placeholder(__('admin.placeholders.none')),

                TextColumn::make('default_duration_minutes')
                    ->label(__('admin.fields.default_duration_minutes'))
                    ->numeric()
                    ->placeholder(__('admin.placeholders.none')),

                IconColumn::make('supports_ongoing')
                    ->label(__('admin.fields.supports_ongoing'))
                    ->boolean(),

                IconColumn::make('is_nightlife')
                    ->label(__('admin.fields.is_nightlife'))
                    ->boolean(),

                TextColumn::make('events_count')
                    ->label(__('admin.fields.events_count'))
                    ->counts('events'),

                IconColumn::make('is_active')
                    ->label(__('admin.fields.is_active'))
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('admin.fields.is_active')),

                TernaryFilter::make('is_nightlife')
                    ->label(__('admin.fields.is_nightlife')),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'edit' => EditCategory::route('/{record}/edit'),
        ];
    }
}
