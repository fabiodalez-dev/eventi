<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SponsorshipOrders;

use App\Enums\PromotionMode;
use App\Enums\SponsorshipOrderStatus;
use App\Enums\SponsorshipPlacement;
use App\Filament\Admin\Resources\SponsorshipOrders\Pages\ListOrders;
use App\Filament\Concerns\HasSentenceCaseNavigationLabel;
use App\Models\SponsorshipOrder;
use App\Models\User;
use App\Services\Sponsorship\SponsorshipOrders;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Gli ordini di sponsorizzazione arrivati dai pannelli.
 *
 * Non si creano da qui — nascono da un acquisto — e non si modificano: si
 * approvano o si rifiutano, e basta. Chi vuole un'abilitazione senza
 * pagamento online la crea fra le abilitazioni, come prima.
 */
class SponsorshipOrderResource extends Resource
{
    use HasSentenceCaseNavigationLabel;

    protected static ?string $model = SponsorshipOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.promotion');
    }

    public static function getModelLabel(): string
    {
        return __('promotions.orders.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('promotions.orders.title');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = SponsorshipOrder::query()->where('status', SponsorshipOrderStatus::Paid)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['venue', 'organizer', 'user', 'decider']))
            ->columns([
                TextColumn::make('created_at')->label(__('promotions.orders.date'))->dateTime('d/m/Y H:i', 'Europe/Rome')->sortable(),
                TextColumn::make('sponsor')->label(__('promotions.sponsor'))->state(fn (SponsorshipOrder $record): string => $record->sponsorName())
                    ->description(fn (SponsorshipOrder $record): string => $record->venue_id !== null ? __('promotions.venue') : __('promotions.organizer')),
                TextColumn::make('placement')->label(__('promotions.placement'))->formatStateUsing(fn (SponsorshipPlacement $state): string => $state->label()),
                TextColumn::make('months')->label(__('promotions.orders.duration'))->formatStateUsing(fn (int $state): string => __('promotions.orders.months_short', ['months' => $state])),
                TextColumn::make('mode')->label(__('promotions.orders.mode'))->formatStateUsing(fn (PromotionMode $state): string => $state->label()),
                TextColumn::make('amount_cents')->label(__('promotions.amount'))->money('EUR', divideBy: 100),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()
                    ->formatStateUsing(fn (SponsorshipOrderStatus $state): string => $state->label())
                    ->color(fn (SponsorshipOrderStatus $state): string => $state->color()),
                TextColumn::make('user.name')->label(__('promotions.orders.buyer'))->placeholder('—'),
                TextColumn::make('paid_at')->label(__('promotions.orders.paid_on'))->dateTime('d/m/Y H:i', 'Europe/Rome')->placeholder('—'),
                TextColumn::make('decided_at')->label(__('promotions.orders.decided_on'))->dateTime('d/m/Y H:i', 'Europe/Rome')->placeholder('—')->toggleable(),
                TextColumn::make('decider.name')->label(__('promotions.orders.decided_by'))->placeholder('—')->toggleable(),
                TextColumn::make('decision_note')->label(__('promotions.orders.note'))->placeholder('—')->wrap()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('payment_intent_id')->label(__('promotions.reference'))->placeholder('—')->copyable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->options(SponsorshipOrderStatus::options()),
            ])
            ->recordActions([
                Action::make('approve')->label(__('promotions.orders.approve'))->icon(Heroicon::OutlinedCheckCircle)->color('success')
                    ->requiresConfirmation()->modalDescription(__('promotions.orders.approve_confirm'))
                    ->visible(fn (SponsorshipOrder $record): bool => $record->status === SponsorshipOrderStatus::Paid && self::canDecide($record))
                    ->action(function (SponsorshipOrder $record): void {
                        $admin = Auth::user();
                        if (! $admin instanceof User) {
                            return;
                        }
                        app(SponsorshipOrders::class)->approve($record, $admin);
                        Notification::make()->title(__('promotions.orders.approved'))->success()->send();
                    }),
                Action::make('reject')->label(__('promotions.orders.reject'))->icon(Heroicon::OutlinedXCircle)->color('danger')
                    ->modalDescription(__('promotions.orders.reject_confirm'))
                    ->visible(fn (SponsorshipOrder $record): bool => $record->status === SponsorshipOrderStatus::Paid && self::canDecide($record))
                    ->schema([Textarea::make('reason')->label(__('promotions.orders.reject_reason'))->required()->maxLength(2000)->rows(3)])
                    ->action(function (SponsorshipOrder $record, array $data): void {
                        $admin = Auth::user();
                        if (! $admin instanceof User) {
                            return;
                        }
                        app(SponsorshipOrders::class)->reject($record, $admin, (string) $data['reason']);
                        Notification::make()->title(__('promotions.orders.rejected'))->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListOrders::route('/')];
    }

    private static function canDecide(SponsorshipOrder $record): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('decide', $record);
    }
}
