<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Redirects;

use App\Filament\Admin\Resources\Redirects\Pages\ManageRedirects;
use App\Models\City;
use App\Models\Redirect;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Gli indirizzi che non esistono più.
 *
 * **Le righe automatiche non bastano, ed è per questo che questa pagina
 * esiste.** Gli observer coprono la rinomina di ciò che ha un modello dietro —
 * un evento, un locale, una categoria, una città. Non coprono una sezione
 * eliminata, un indirizzo di un vecchio sito, un percorso stampato su un
 * volantino con un refuso: quelli li conosce solo una persona, e senza un posto
 * dove scriverli restano 404 per sempre.
 *
 * **I passaggi sono metà del valore.** Un elenco che si può solo allungare è un
 * elenco che nessuno rilegge e nessuno osa potare. Con il contatore si vede
 * quali ponti portano ancora qualcuno — e capita di scoprire che l'indirizzo
 * più visitato del sito è uno che non esiste più.
 */
class RedirectResource extends Resource
{
    protected static ?string $model = Redirect::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnRight;

    protected static ?int $navigationSort = 93;

    protected static ?string $slug = 'indirizzi-cambiati';

    public static function getNavigationLabel(): string
    {
        return __('redirects.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.system');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('from_path')
                ->label(__('redirects.fields.from'))
                ->required()
                ->maxLength(191)
                ->helperText(__('redirects.fields.from_help')),

            TextInput::make('to_path')
                ->label(__('redirects.fields.to'))
                ->required()
                ->maxLength(191)
                ->helperText(__('redirects.fields.to_help')),

            Select::make('city_id')
                ->label(__('redirects.fields.city'))
                ->options(fn (): array => City::query()->orderBy('name')->pluck('name', 'id')->all())
                ->placeholder(__('redirects.fields.city_any'))
                ->helperText(__('redirects.fields.city_help')),

            Select::make('status')
                ->label(__('redirects.fields.status'))
                ->options([
                    301 => __('redirects.status.permanent'),
                    302 => __('redirects.status.temporary'),
                ])
                ->default(301)
                ->required(),

            Toggle::make('is_wildcard')
                ->label(__('redirects.fields.wildcard'))
                ->helperText(__('redirects.fields.wildcard_help')),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            /*
             * In cima le righe attraversate più di recente: sono quelle che
             * dicono qualcosa. Una riga ferma da un anno è candidata a sparire,
             * e sta in fondo perché è lì che si va a cercarla.
             */
            ->defaultSort('last_hit_at', 'desc')
            ->emptyStateHeading(__('redirects.empty.heading'))
            ->emptyStateDescription(__('redirects.empty.description'))
            ->columns([
                TextColumn::make('from_path')
                    ->label(__('redirects.fields.from'))
                    ->searchable()
                    ->weight('bold'),

                TextColumn::make('to_path')
                    ->label(__('redirects.fields.to'))
                    ->searchable(),

                TextColumn::make('city.name')
                    ->label(__('redirects.fields.city'))
                    ->placeholder(__('redirects.fields.city_any')),

                TextColumn::make('hits')
                    ->label(__('redirects.fields.hits'))
                    ->numeric()
                    ->sortable(),

                TextColumn::make('last_hit_at')
                    ->label(__('redirects.fields.last_hit_at'))
                    ->dateTime()
                    ->placeholder(__('redirects.fields.never'))
                    ->sortable(),
            ]);
    }

    /** @return array<string, mixed> */
    public static function getPages(): array
    {
        return ['index' => ManageRedirects::route('/')];
    }
}
