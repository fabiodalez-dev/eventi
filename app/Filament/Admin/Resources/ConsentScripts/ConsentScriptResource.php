<?php

namespace App\Filament\Admin\Resources\ConsentScripts;

use App\Enums\ConsentCategory;
use App\Models\ConsentScript;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ConsentScriptResource extends Resource
{
    protected static ?string $model = ConsentScript::class;

    protected static ?string $slug = 'script-consenso';

    public static function getNavigationLabel(): string
    {
        return __('consent_scripts.title');
    }

    public static function getModelLabel(): string
    {
        return __('consent_scripts.single');
    }

    public static function getPluralModelLabel(): string
    {
        return __('consent_scripts.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.system');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('name')->label(__('consent_scripts.name'))->required()->maxLength(255),
            Select::make('category')->label(__('consent_scripts.category'))->options(ConsentCategory::options())->required()->helperText(__('consent_scripts.category_help')),
            Toggle::make('enabled')->label(__('consent_scripts.enabled'))->default(false),
            TextInput::make('sort_order')->label(__('consent_scripts.order'))->numeric()->minValue(0)->default(0)->required(),
            TextInput::make('src')->label(__('consent_scripts.src'))->url()->rules(['nullable', 'starts_with:https://'])->requiredWithout('code'),
            Textarea::make('code')->label(__('consent_scripts.code'))->rows(10)->maxLength(60000)->requiredWithout('src')->rules(['nullable', 'not_regex:~</?script\\b~i'])->helperText(__('consent_scripts.code_help'))->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort_order')->columns([
            TextColumn::make('name')->label(__('consent_scripts.name'))->searchable(),
            TextColumn::make('category')->label(__('consent_scripts.category'))->formatStateUsing(fn (ConsentCategory $state) => $state->label()),
            IconColumn::make('enabled')->label(__('consent_scripts.enabled'))->boolean(),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageConsentScripts::route('/')];
    }
}
