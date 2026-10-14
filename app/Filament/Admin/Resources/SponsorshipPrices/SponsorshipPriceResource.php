<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SponsorshipPrices;

use App\Enums\SponsorshipPlacement;
use App\Filament\Admin\Resources\SponsorshipPrices\Pages\CreatePrice;
use App\Filament\Admin\Resources\SponsorshipPrices\Pages\EditPrice;
use App\Filament\Admin\Resources\SponsorshipPrices\Pages\ListPrices;
use App\Filament\Concerns\HasSentenceCaseNavigationLabel;
use App\Models\SponsorshipPrice;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/** Il listino: una posizione per una durata a un prezzo. */
class SponsorshipPriceResource extends Resource
{
    use HasSentenceCaseNavigationLabel;

    protected static ?string $model = SponsorshipPrice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyEuro;

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.promotion');
    }

    public static function getModelLabel(): string
    {
        return __('promotions.prices.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('promotions.prices.title');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('promotions.prices.title'))->description(__('promotions.prices.help'))->columns(2)->schema([
                Select::make('placement')->label(__('promotions.placement'))->options(SponsorshipPlacement::options())->required()->live(),
                Select::make('months')->label(__('promotions.prices.months'))->options(array_combine(SponsorshipPrice::MONTHS, array_map(fn (int $months): string => (string) $months, SponsorshipPrice::MONTHS)))->required()
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('placement', $get('placement')))
                    ->validationMessages(['unique' => __('promotions.prices.duplicate')]),
                TextInput::make('amount_cents')->label(__('promotions.prices.amount'))->numeric()->step(0.01)->rules(['decimal:0,2'])->minValue(0.01)->maxValue(1000000)->required()
                    ->formatStateUsing(fn ($state) => $state === null ? null : $state / 100)
                    ->dehydrateStateUsing(fn ($state) => filled($state) ? (int) round((float) $state * 100) : null),
                Toggle::make('is_active')->label(__('promotions.prices.active'))->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('placement')->columns([
            TextColumn::make('placement')->label(__('promotions.placement'))->formatStateUsing(fn (SponsorshipPlacement $state): string => $state->label())->sortable(),
            TextColumn::make('months')->label(__('promotions.prices.months'))->sortable(),
            TextColumn::make('amount_cents')->label(__('promotions.prices.amount'))->money('EUR', divideBy: 100)->sortable(),
            IconColumn::make('is_active')->label(__('promotions.prices.active'))->boolean(),
        ])->filters([TernaryFilter::make('is_active')->label(__('promotions.prices.active'))])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPrices::route('/'), 'create' => CreatePrice::route('/create'), 'edit' => EditPrice::route('/{record}/edit')];
    }
}
