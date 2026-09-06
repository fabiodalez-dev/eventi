<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Pages;

use App\Filament\Admin\Resources\Pages\Pages\CreatePage;
use App\Filament\Admin\Resources\Pages\Pages\EditPage;
use App\Filament\Admin\Resources\Pages\Pages\ListPages;
use App\Models\Page;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Textarea;
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

/**
 * Le pagine informative di §11.1 e §16 — privacy, cookie, termini, chi siamo,
 * contatti — modificabili senza un rilascio.
 *
 * ## Perché il testo è Markdown e non un editor ricco
 *
 * Un editor ricco salva HTML, e HTML scritto da qualcuno e ristampato in pagina
 * va ripulito con una whitelist da mantenere aggiornata (§16). Il Markdown
 * viene convertito **scartando** ogni marcatura grezza: non esiste un percorso
 * per cui uno `<script>` o un `<iframe>` finisca nella pagina, e non c'è
 * whitelist da tenere allineata. L'editor di Filament dà comunque la barra
 * degli strumenti e l'anteprima, quindi chi scrive non deve conoscere la
 * sintassi.
 *
 * ## Lo slug si scrive una volta
 *
 * `Page::getSlugOptions()` non lo rigenera al salvataggio: l'indirizzo di
 * un'informativa privacy finisce nei registri dei trattamenti e nelle email già
 * partite. Resta modificabile a mano, ma è un gesto dichiarato.
 */
class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 9;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.content');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.page.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.page.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)
            ->components([
                Section::make(__('admin.sections.content'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->label(__('admin.fields.title'))
                            ->required()
                            ->maxLength(255),

                        TextInput::make('slug')
                            ->label(__('admin.fields.slug'))
                            ->helperText(__('admin.hints.page_slug'))
                            ->maxLength(191)
                            ->unique(ignoreRecord: true)
                            ->rule('regex:/^[a-z0-9][a-z0-9-]*$/'),

                        Textarea::make('excerpt')
                            ->label(__('admin.fields.excerpt'))
                            ->helperText(__('admin.hints.page_excerpt'))
                            ->rows(2)
                            ->maxLength(500)
                            ->columnSpanFull(),

                        MarkdownEditor::make('body')
                            ->label(__('admin.fields.body'))
                            ->helperText(__('admin.hints.page_body'))
                            ->required()
                            ->columnSpanFull(),
                    ]),

                Section::make(__('admin.sections.publication'))
                    ->columns(2)
                    ->schema([
                        Toggle::make('is_published')
                            ->label(__('admin.fields.is_published'))
                            ->helperText(__('admin.hints.page_is_published')),

                        TextInput::make('sort_order')
                            ->label(__('admin.fields.sort_order'))
                            ->helperText(__('admin.hints.page_sort_order'))
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->maxValue(65535),
                    ]),

                Section::make(__('admin.sections.seo'))
                    ->columns(1)
                    ->collapsed()
                    ->schema([
                        TextInput::make('seo_title')
                            ->label(__('admin.fields.seo_title'))
                            ->placeholder(__('admin.placeholders.auto'))
                            ->maxLength(255),

                        Textarea::make('seo_description')
                            ->label(__('admin.fields.seo_description'))
                            ->placeholder(__('admin.placeholders.auto'))
                            ->rows(2)
                            ->maxLength(500),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('admin.fields.title'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('slug')
                    ->label(__('admin.fields.slug'))
                    ->searchable(),

                IconColumn::make('is_published')
                    ->label(__('admin.fields.is_published'))
                    ->boolean(),

                TextColumn::make('sort_order')
                    ->label(__('admin.fields.sort_order'))
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label(__('admin.fields.updated_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                TernaryFilter::make('is_published')
                    ->label(__('admin.fields.is_published')),
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
            'index' => ListPages::route('/'),
            'create' => CreatePage::route('/create'),
            'edit' => EditPage::route('/{record}/edit'),
        ];
    }
}
