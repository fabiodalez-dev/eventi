<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CookieDeclarations;

use App\Enums\ConsentCategory;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\CookieDeclarations\Pages\ManageCookieDeclarations;
use App\Models\CookieDeclaration;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Il registro dei cookie, diviso per finalità.
 *
 * **Tre schede e non un elenco unico.** Le tre finalità non sono un'etichetta
 * sulla stessa cosa: la prima non si rifiuta, le altre due sì, e chi compila
 * deve vedere subito in quale sta scrivendo. Un elenco unico con una colonna
 * «categoria» costringe a leggere ogni riga per capire cosa comporta.
 */
class CookieDeclarationResource extends Resource
{
    protected static ?string $model = CookieDeclaration::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 92;

    protected static ?string $slug = 'cookie';

    public static function canAccess(): bool
    {
        $utente = Auth::user();

        /*
         * Solo chi amministra. Un elenco di cookie è il contenuto di
         * un'informativa legale: sbagliarlo non rompe niente sul sito e
         * dichiara il falso a chi legge, che è peggio.
         */
        return $utente instanceof User
            && ($utente->hasRole(UserRole::Admin->value) || $utente->hasRole(UserRole::SuperAdmin->value));
    }

    public static function getNavigationLabel(): string
    {
        return __('cookies.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.system');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Select::make('category')
                ->label(__('cookies.fields.category'))
                ->options(ConsentCategory::options())
                ->required()
                ->helperText(__('cookies.fields.category_help')),

            TextInput::make('name')
                ->label(__('cookies.fields.name'))
                ->required()
                ->maxLength(255)
                ->helperText(__('cookies.fields.name_help')),

            TextInput::make('provider')
                ->label(__('cookies.fields.provider'))
                ->maxLength(255)
                ->helperText(__('cookies.fields.provider_help')),

            TextInput::make('purpose')
                ->label(__('cookies.fields.purpose'))
                ->required()
                ->maxLength(255)
                ->helperText(__('cookies.fields.purpose_help')),

            TextInput::make('duration')
                ->label(__('cookies.fields.duration'))
                ->maxLength(64)
                ->helperText(__('cookies.fields.duration_help')),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->label(__('cookies.fields.name'))
                    ->searchable()
                    ->weight('bold'),

                TextColumn::make('provider')
                    ->label(__('cookies.fields.provider'))
                    ->placeholder(__('cookies.own'))
                    ->searchable(),

                TextColumn::make('purpose')
                    ->label(__('cookies.fields.purpose'))
                    ->wrap()
                    ->searchable(),

                TextColumn::make('duration')
                    ->label(__('cookies.fields.duration'))
                    ->placeholder('—'),
            ]);
    }

    /** @return array<string, mixed> */
    public static function getPages(): array
    {
        return ['index' => ManageCookieDeclarations::route('/')];
    }
}
