<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Tags;

use App\Actions\MergeTagsAction;
use App\Filament\Admin\Resources\Tags\Pages\CreateTag;
use App\Filament\Admin\Resources\Tags\Pages\EditTag;
use App\Filament\Admin\Resources\Tags\Pages\ListTags;
use App\Models\Tag;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * §9.2 — i tag, con l'unione dei doppioni. Il lavoro vero lo fa
 * `MergeTagsAction`: qui c'è solo il modulo che chiede quale tag assorbire.
 */
class TagResource extends Resource
{
    protected static ?string $model = Tag::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.content');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.tag.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.tag.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
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

                        Select::make('category_id')
                            ->label(__('admin.fields.category'))
                            ->relationship('category', 'name')
                            ->searchable()
                            ->preload(),

                        Toggle::make('is_approved')
                            ->label(__('admin.fields.is_approved')),

                        TagsInput::make('synonyms')
                            ->label(__('admin.fields.synonyms'))
                            ->columnSpanFull(),
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

                TextColumn::make('category.name')
                    ->label(__('admin.fields.category'))
                    ->placeholder(__('admin.placeholders.none')),

                TextColumn::make('events_count')
                    ->label(__('admin.fields.events_count'))
                    ->counts('events')
                    ->sortable(),

                TextColumn::make('usage_count')
                    ->label(__('admin.fields.usage_count'))
                    ->numeric()
                    ->sortable(),

                IconColumn::make('is_approved')
                    ->label(__('admin.fields.is_approved'))
                    ->boolean(),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('is_approved')
                    ->label(__('admin.fields.is_approved')),
            ])
            ->recordActions([
                EditAction::make(),
                self::mergeAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    private static function mergeAction(): Action
    {
        return Action::make('merge')
            ->label(__('admin.actions.merge'))
            ->icon(Heroicon::OutlinedArrowsPointingIn)
            ->requiresConfirmation()
            ->modalDescription(__('admin.confirmations.merge'))
            ->authorize(fn (Tag $record): bool => auth()->user()?->can('delete', $record) ?? false)
            ->schema([
                Select::make('absorbed')
                    ->label(__('admin.fields.merge_target'))
                    ->helperText(__('admin.hints.merge'))
                    ->required()
                    ->searchable()
                    ->options(fn (Tag $record): array => Tag::query()
                        ->whereKeyNot($record->getKey())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),
            ])
            ->action(function (Tag $record, array $data): void {
                /** @var Tag $absorbed */
                $absorbed = Tag::query()->findOrFail($data['absorbed']);

                $moved = app(MergeTagsAction::class)->execute($record, $absorbed);

                Notification::make()
                    ->title(__('admin.notifications.merged', ['count' => $moved]))
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListTags::route('/'),
            'create' => CreateTag::route('/create'),
            'edit' => EditTag::route('/{record}/edit'),
        ];
    }
}
