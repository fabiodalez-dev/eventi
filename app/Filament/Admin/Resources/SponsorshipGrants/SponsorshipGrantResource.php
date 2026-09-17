<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SponsorshipGrants;

use App\Enums\PromotionMode;
use App\Enums\SponsorshipPlacement;
use App\Filament\Admin\Resources\Events\RelationManagers\ActivityRelationManager;
use App\Models\SponsorshipGrant;
use App\Services\Sponsorship\GrantPayment;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SponsorshipGrantResource extends Resource
{
    protected static ?string $model = SponsorshipGrant::class;

    public static function getNavigationGroup(): ?string
    {
        return __('sponsorships.admin.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('promotions.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('promotions.title');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('promotions.rules'))->description(__('promotions.help'))->columns(2)->schema([
                Select::make('venue_id')->label(__('promotions.venue'))->relationship('venue', 'name')->searchable()->preload()->required()->disabledOn('edit'),
                Select::make('mode')->label(__('promotions.mode_label'))->options(PromotionMode::options())->default('selected')->required()->disabledOn('edit'),
                Select::make('placement')->label(__('promotions.placement'))->options(SponsorshipPlacement::options())->default('list_top')->required()->disabledOn('edit'),
                Toggle::make('enabled')->label(__('promotions.enabled'))->default(true),
            ]),
            Section::make(__('promotions.period'))->columns(2)->schema([
                DateTimePicker::make('starts_at')->label(__('promotions.starts'))->timezone('Europe/Rome')->default(now())->required()->seconds(false)->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::updateEndDate($get, $set)),
                Select::make('months')->label(__('promotions.months'))->options([1 => '1', 2 => '2', 3 => '3', 6 => '6', 12 => '12'])->dehydrated(false)->live()
                    ->placeholder(__('promotions.custom_duration'))
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::updateEndDate($get, $set)),
                DateTimePicker::make('ends_at')->label(__('promotions.ends'))->timezone('Europe/Rome')->default(now()->addMonthNoOverflow())->required()->after('starts_at')->seconds(false)->helperText(__('promotions.manual'))->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set) => $set('months', null)),
                Toggle::make('complimentary')->label(__('promotions.complimentary'))->live()
                    ->helperText(__('promotions.complimentary_payment_hint'))
                    ->afterStateUpdated(function ($state, Set $set): void {
                        if ($state) {
                            foreach (GrantPayment::FIELDS as $field) {
                                $set($field, null);
                            }
                        }
                    }),
                TextInput::make('amount_cents')->label(__('promotions.amount'))->numeric()->step(0.01)->rules(['decimal:0,2'])->minValue(0.01)->maxValue(1000000)
                    ->formatStateUsing(fn ($state) => $state === null ? null : $state / 100)
                    ->dehydrateStateUsing(fn ($state) => filled($state) ? (int) round((float) $state * 100) : null)
                    ->required(fn (Get $get): bool => ! $get('complimentary'))->visible(fn (Get $get): bool => ! $get('complimentary')),
                DateTimePicker::make('paid_at')->label(__('promotions.paid'))->timezone('Europe/Rome')->maxDate(fn () => now())->seconds(false)->visible(fn (Get $get): bool => ! $get('complimentary')),
                TextInput::make('payment_method')->label(__('promotions.method'))->maxLength(100)->visible(fn (Get $get): bool => ! $get('complimentary')),
                TextInput::make('payment_reference')->label(__('promotions.reference'))->maxLength(255)->visible(fn (Get $get): bool => ! $get('complimentary')),
                Textarea::make('notes')->label(__('promotions.notes'))->required(fn (Get $get): bool => (bool) $get('complimentary'))->maxLength(5000)->columnSpanFull(),
            ]),
        ]);
    }

    private static function updateEndDate(Get $get, Set $set): void
    {
        $months = filter_var($get('months'), FILTER_VALIDATE_INT);
        $start = $get('starts_at');
        if (! in_array($months, [1, 2, 3, 6, 12], true) || ! is_string($start) || blank($start)) {
            return;
        }
        try {
            $date = CarbonImmutable::parse($start, config('app.timezone'))->setTimezone('Europe/Rome');
        } catch (InvalidFormatException) {
            // Leave validation to the form while a date is incomplete.
            return;
        }

        // Get/Set use application time; add calendar months in the displayed timezone.
        $set('ends_at', $date->addMonthsNoOverflow($months)->setTimezone(config('app.timezone')));
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('ends_at', 'desc')->columns([
            TextColumn::make('venue.name')->label(__('promotions.venue'))->searchable()->sortable(),
            TextColumn::make('effective_status')->label(__('admin.fields.status'))->state(fn (SponsorshipGrant $record): string => $record->statusLabel())->badge(),
            TextColumn::make('mode')->label(__('promotions.mode_label'))->formatStateUsing(fn (PromotionMode $state): string => $state->label()),
            IconColumn::make('enabled')->label(__('promotions.enabled'))->boolean(),
            IconColumn::make('complimentary')->label(__('promotions.complimentary'))->boolean(),
            TextColumn::make('starts_at')->label(__('promotions.starts'))->dateTime('d/m/Y H:i', 'Europe/Rome'),
            TextColumn::make('ends_at')->label(__('promotions.ends'))->dateTime('d/m/Y H:i', 'Europe/Rome')->sortable(),
            TextColumn::make('paid_at')->label(__('promotions.paid'))->date('d/m/Y')->placeholder('—'),
            TextColumn::make('amount_cents')->label(__('promotions.amount'))->money('EUR', divideBy: 100),
            TextColumn::make('sponsorships_sum_impressions')->label(__('promotions.views'))->sum('sponsorships', 'impressions')->numeric(),
            TextColumn::make('sponsorships_sum_clicks')->label(__('promotions.clicks'))->sum('sponsorships', 'clicks')->numeric(),
        ])->filters([SelectFilter::make('venue')->label(__('promotions.venue'))->relationship('venue', 'name')->searchable()->preload()])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListGrants::route('/'), 'create' => Pages\CreateGrant::route('/create'), 'edit' => Pages\EditGrant::route('/{record}/edit')];
    }

    public static function getRelations(): array
    {
        return [ActivityRelationManager::class];
    }
}
