<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SponsorshipOrders;

use App\Enums\PromotionMode;
use App\Enums\SponsorshipOrderStatus;
use App\Enums\SponsorshipPlacement;
use App\Filament\Admin\Resources\SponsorshipOrders\Pages\ListOrders;
use App\Filament\Concerns\HasSentenceCaseNavigationLabel;
use App\Filament\Support\PosterColumn;
use App\Models\SponsorshipOrder;
use App\Models\User;
use App\Services\Sponsorship\SponsorshipOrders;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Gli ordini di sponsorizzazione arrivati dai pannelli.
 *
 * Non si creano da qui — nascono da un acquisto o da una richiesta — e non
 * si modificano: si approvano o si rifiutano, e basta. Una richiesta senza
 * pagamento online si approva dicendo come e' stata saldata (pagamento
 * registrato a mano, oppure gratis con una motivazione); un ordine pagato
 * con Stripe porta gia' il suo pagamento.
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
        $count = SponsorshipOrder::query()->whereIn('status', SponsorshipOrderStatus::awaitingDecision())->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('promotions.orders.awaiting_badge');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['venue', 'organizer', 'user', 'decider', 'event.media']))
            ->columns([
                PosterColumn::make('event.poster_media'),
                TextColumn::make('created_at')->label(__('promotions.orders.date'))->dateTime('d/m/Y H:i', 'Europe/Rome')->sortable(),
                TextColumn::make('sponsor')->label(__('promotions.sponsor'))->state(fn (SponsorshipOrder $record): string => $record->sponsorName())
                    ->description(fn (SponsorshipOrder $record): string => $record->venue_id !== null ? __('promotions.venue') : __('promotions.organizer')),
                TextColumn::make('event.title')->label(__('promotions.orders.event'))->placeholder(__('promotions.request.all_events'))->wrap()->limit(60),
                TextColumn::make('placement')->label(__('promotions.placement'))->formatStateUsing(fn (SponsorshipPlacement $state): string => $state->label()),
                TextColumn::make('months')->label(__('promotions.orders.duration'))->formatStateUsing(fn (int $state): string => __('promotions.orders.months_short', ['months' => $state])),
                TextColumn::make('mode')->label(__('promotions.orders.mode'))->formatStateUsing(fn (PromotionMode $state): string => $state->label())->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('amount_cents')->label(__('promotions.amount'))
                    ->formatStateUsing(fn (SponsorshipOrder $record): string => $record->amount_cents > 0 ? $record->formattedAmount() : __('promotions.request.price_to_agree')),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()
                    ->formatStateUsing(fn (SponsorshipOrderStatus $state): string => $state->label())
                    ->color(fn (SponsorshipOrderStatus $state): string => $state->color()),
                TextColumn::make('user.name')->label(__('promotions.orders.buyer'))->placeholder('—')->toggleable(),
                TextColumn::make('paid_at')->label(__('promotions.orders.paid_on'))->dateTime('d/m/Y H:i', 'Europe/Rome')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('decided_at')->label(__('promotions.orders.decided_on'))->dateTime('d/m/Y H:i', 'Europe/Rome')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('decider.name')->label(__('promotions.orders.decided_by'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('request_note')->label(__('promotions.orders.request_note'))->placeholder('—')->wrap()->limit(120)->toggleable(),
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
                        self::decide($record, fn () => app(SponsorshipOrders::class)->approve($record, $admin), __('promotions.orders.approved'));
                    }),
                /* Una richiesta senza pagamento online: si dice come e' stata
                   saldata, e l'abilitazione nasce con quei dati. */
                Action::make('approveRequest')->label(__('promotions.orders.approve'))->icon(Heroicon::OutlinedCheckCircle)->color('success')
                    ->modalDescription(__('promotions.orders.approve_request_lead'))
                    ->visible(fn (SponsorshipOrder $record): bool => $record->status === SponsorshipOrderStatus::Requested && self::canDecide($record))
                    ->fillForm(fn (SponsorshipOrder $record): array => [
                        'settlement' => 'paid', 'payment_method' => 'bank_transfer',
                        'amount' => $record->amount_cents > 0 ? number_format($record->amount_cents / 100, 2, '.', '') : null,
                    ])
                    ->schema([
                        ToggleButtons::make('settlement')->label(__('promotions.orders.settlement'))->required()->inline()->live()
                            ->options(['paid' => __('promotions.orders.settlement_paid'), 'free' => __('promotions.orders.settlement_free')]),
                        TextInput::make('amount')->label(__('promotions.orders.settlement_amount'))->numeric()->minValue(0.01)->maxValue(100000)->prefix('€')
                            ->required(fn (Get $get): bool => $get('settlement') === 'paid')->visible(fn (Get $get): bool => $get('settlement') === 'paid'),
                        Select::make('payment_method')->label(__('promotions.orders.settlement_method'))->options(__('promotions.orders.methods'))
                            ->required(fn (Get $get): bool => $get('settlement') === 'paid')->visible(fn (Get $get): bool => $get('settlement') === 'paid'),
                        TextInput::make('payment_reference')->label(__('promotions.orders.settlement_reference'))->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('settlement') === 'paid'),
                        Textarea::make('reason')->label(__('promotions.orders.settlement_reason'))->rows(3)->maxLength(2000)
                            ->required(fn (Get $get): bool => $get('settlement') === 'free')->visible(fn (Get $get): bool => $get('settlement') === 'free'),
                    ])
                    ->action(function (SponsorshipOrder $record, array $data): void {
                        $admin = Auth::user();
                        if (! $admin instanceof User) {
                            return;
                        }
                        $free = ($data['settlement'] ?? 'paid') === 'free';
                        self::decide($record, fn () => app(SponsorshipOrders::class)->approve($record, $admin, $free
                            ? ['complimentary' => true, 'notes' => (string) ($data['reason'] ?? '')]
                            : ['amount_cents' => (int) round((float) ($data['amount'] ?? 0) * 100), 'payment_method' => (string) ($data['payment_method'] ?? 'other'),
                                'payment_reference' => filled($data['payment_reference'] ?? null) ? (string) $data['payment_reference'] : null]), __('promotions.orders.approved'));
                    }),
                Action::make('reject')->label(__('promotions.orders.reject'))->icon(Heroicon::OutlinedXCircle)->color('danger')
                    ->modalDescription(fn (SponsorshipOrder $record): string => $record->status === SponsorshipOrderStatus::Requested
                        ? __('promotions.orders.reject_request_confirm') : __('promotions.orders.reject_confirm'))
                    ->visible(fn (SponsorshipOrder $record): bool => $record->status->awaitsDecision() && self::canDecide($record))
                    ->schema([Textarea::make('reason')->label(__('promotions.orders.reject_reason'))->required()->maxLength(2000)->rows(3)])
                    ->action(function (SponsorshipOrder $record, array $data): void {
                        $admin = Auth::user();
                        if (! $admin instanceof User) {
                            return;
                        }
                        self::decide($record, fn () => app(SponsorshipOrders::class)->reject($record, $admin, (string) $data['reason']), __('promotions.orders.rejected'));
                    }),
            ]);
    }

    /**
     * Una decisione presa mentre un'altra persona decideva lo stesso ordine:
     * `transitionTo` rifiuta il passaggio sulla riga riletta e bloccata. Lo
     * dice una notifica con lo stato di adesso, invece di una pagina
     * d'errore.
     *
     * @param  \Closure(): mixed  $decision
     */
    private static function decide(SponsorshipOrder $record, \Closure $decision, string $done): void
    {
        try {
            $decision();
        } catch (\InvalidArgumentException) {
            $status = $record->refresh()->status;
            Notification::make()->title(__('promotions.orders.decided_meanwhile', ['status' => $status->label()]))->danger()->send();

            return;
        }
        Notification::make()->title($done)->success()->send();
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
