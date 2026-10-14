<?php

declare(strict_types=1);

namespace App\Filament\Shared;

use App\Enums\PromotionMode;
use App\Enums\SponsorshipOrderStatus;
use App\Enums\SponsorshipStatus;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\Sponsorship;
use App\Models\SponsorshipGrant;
use App\Models\SponsorshipOrder;
use App\Models\SponsorshipPrice;
use App\Models\User;
use App\Models\Venue;
use App\Services\Sponsorship\GrantCampaigns;
use App\Services\Sponsorship\Payment\PaymentGatewayException;
use App\Services\Sponsorship\SponsorshipOrders;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\WithPagination;

/**
 * Sponsorizzazioni dal pannello di un committente: abilitazioni, campagne,
 * ordini e acquisto.
 *
 * Una classe per i due pannelli perche' locale e organizzatore fanno le
 * stesse cose; cambia solo chi e' il committente (`sponsor()`), e quello lo
 * dicono le sottoclassi.
 *
 * La scelta degli eventi passa sempre da `GrantCampaigns`, l'acquisto da
 * `SponsorshipOrders`: qui si disegna e si autorizza, non si decide.
 */
abstract class SponsorshipsPage extends Page
{
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?int $navigationSort = 7;

    protected static ?string $slug = 'sponsorizzazioni';

    protected string $view = 'filament.shared.sponsorships';

    abstract protected function sponsor(): Venue|Organizer;

    abstract protected function analyticsUrl(): ?string;

    public static function getNavigationLabel(): string
    {
        return __('sponsorships.venue.title');
    }

    public function getTitle(): string
    {
        return __('sponsorships.venue.title');
    }

    public function getSubheading(): ?string
    {
        return __('promotions.venue_lead');
    }

    /**
     * Il ritorno dal checkout: `?order=ID&session_id=…` dopo aver pagato,
     * `?order=ID&cancelled=1` dopo aver chiuso la pagina di Stripe.
     */
    public function mount(): void
    {
        $orderId = request()->integer('order');

        if ($orderId === 0) {
            return;
        }

        $order = SponsorshipOrder::query()->forSponsor($this->sponsor())->find($orderId);

        if ($order === null) {
            return;
        }

        if (request()->boolean('cancelled')) {
            Notification::make()->title(__('promotions.orders.returned_cancelled'))->warning()->send();

            return;
        }

        try {
            app(SponsorshipOrders::class)->reconcile($order);
        } catch (PaymentGatewayException) {
            /* Stripe non risponde: il webhook arrivera' comunque, e intanto
               la pagina dice solo che il pagamento non risulta ancora. */
        }

        $order->refresh();

        $order->status === SponsorshipOrderStatus::Paid
            ? Notification::make()->title(__('promotions.orders.returned_paid'))->success()->send()
            : Notification::make()->title(__('promotions.orders.returned_pending'))->warning()->send();
    }

    protected function getHeaderActions(): array
    {
        $actions = [];
        $analytics = $this->analyticsUrl();

        if ($analytics !== null) {
            $actions[] = Action::make('analytics')->label(__('sponsorships.venue.analytics'))->url($analytics)->color('gray');
        }

        $actions[] = Action::make('buy')->label(__('promotions.orders.buy'))->icon(Heroicon::OutlinedShoppingCart)
            ->modalDescription(__('promotions.orders.buy_lead'))->modalSubmitActionLabel(__('promotions.orders.pay'))
            ->visible(fn (): bool => $this->canPurchase() && app(SponsorshipOrders::class)->available())
            ->schema([
                Select::make('price')->label(__('promotions.orders.price'))->required()
                    ->options(fn () => SponsorshipPrice::active()->orderBy('placement')->orderBy('months')->get()->mapWithKeys(fn (SponsorshipPrice $price) => [$price->getKey() => $price->label()])->all()),
                Select::make('mode')->label(__('promotions.orders.mode'))->required()->options(PromotionMode::options())
                    ->default(PromotionMode::Selected->value)->helperText(__('promotions.orders.mode_hint')),
            ])
            ->action(function (array $data): void {
                $price = SponsorshipPrice::active()->findOrFail($data['price']);
                $mode = PromotionMode::from((string) $data['mode']);

                try {
                    $order = app(SponsorshipOrders::class)->place($this->buyer(), $this->sponsor(), $price, $mode, $this->successUrl(), $this->cancelUrl());
                } catch (PaymentGatewayException) {
                    Notification::make()->title(__('promotions.orders.checkout_failed'))->danger()->send();

                    return;
                }

                $this->redirectToCheckout($order);
            });

        $actions[] = Action::make('choose')->label(__('promotions.choose'))->schema([
            Select::make('grant')->label(__('promotions.grant'))->required()->options(fn () => $this->selectableGrants()
                ->mapWithKeys(fn (SponsorshipGrant $g) => [$g->getKey() => $g->placement->label().' · '.$g->ends_at->timezone('Europe/Rome')->format('d/m/Y')])->all()),
            Select::make('event')->label(__('promotions.event'))->required()->searchable()->options(fn () => $this->eventsOfSponsor()->orderBy('title')->pluck('title', 'id')),
        ])->visible(fn (): bool => $this->selectableGrants()->isNotEmpty())
            ->action(function (array $data): void {
                $grant = SponsorshipGrant::query()->forSponsor($this->sponsor())->findOrFail($data['grant']);
                $event = $this->eventsOfSponsor()->findOrFail($data['event']);
                app(GrantCampaigns::class)->choose($this->buyer(), $grant, $event);
                Notification::make()->title(__('promotions.success'))->success()->send();
            });

        return $actions;
    }

    /** @return Collection<int, SponsorshipGrant> */
    public function grants(): Collection
    {
        return SponsorshipGrant::query()->forSponsor($this->sponsor())->orderByDesc('ends_at')->get();
    }

    /** @return Collection<int, SponsorshipOrder> */
    public function orders(): Collection
    {
        return SponsorshipOrder::query()->forSponsor($this->sponsor())->latest('id')->get();
    }

    public function retry(int $id): void
    {
        Gate::authorize('purchase', [SponsorshipOrder::class, $this->sponsor()]);
        $order = SponsorshipOrder::query()->forSponsor($this->sponsor())
            ->where('status', SponsorshipOrderStatus::PendingPayment)->findOrFail($id);

        try {
            app(SponsorshipOrders::class)->openCheckout($order, $this->successUrl(), $this->cancelUrl());
        } catch (PaymentGatewayException) {
            Notification::make()->title(__('promotions.orders.checkout_failed'))->danger()->send();

            return;
        }

        $this->redirectToCheckout($order);
    }

    public function cancelOrder(int $id): void
    {
        Gate::authorize('purchase', [SponsorshipOrder::class, $this->sponsor()]);
        $order = SponsorshipOrder::query()->forSponsor($this->sponsor())
            ->where('status', SponsorshipOrderStatus::PendingPayment)->findOrFail($id);
        app(SponsorshipOrders::class)->cancel($order);
        Notification::make()->title(__('promotions.orders.cancelled'))->success()->send();
    }

    public function stop(int $id): void
    {
        $campaign = Sponsorship::query()->whereHas('event', fn (Builder $q) => $this->scopeEventsOfSponsor($q))
            ->whereHas('grant', fn (Builder $q) => $q->where('mode', PromotionMode::Selected)->forSponsor($this->sponsor()))->findOrFail($id);
        Gate::authorize('update', $campaign->event);
        $campaign->update(['status' => SponsorshipStatus::Paused]);
        Notification::make()->title(__('promotions.stopped'))->success()->send();
    }

    /** @return LengthAwarePaginator<int, Sponsorship> */
    public function sponsorships(): LengthAwarePaginator
    {
        return $this->campaignsQuery()->with(['event', 'grant'])->orderByDesc('ends_at')->paginate(25);
    }

    /** @return array{impressions: int, clicks: int} */
    public function totals(): array
    {
        $totals = $this->campaignsQuery()
            ->selectRaw('COALESCE(SUM(impressions), 0) as impressions, COALESCE(SUM(clicks), 0) as clicks')->first();

        return ['impressions' => (int) $totals?->impressions, 'clicks' => (int) $totals?->clicks];
    }

    /** @return Builder<Sponsorship> */
    private function campaignsQuery(): Builder
    {
        return Sponsorship::query()
            ->whereHas('event', fn (Builder $q) => $this->scopeEventsOfSponsor($q))
            ->where(fn (Builder $q) => $q->whereNull('sponsorship_grant_id')->orWhereHas('grant', fn (Builder $g) => $g->forSponsor($this->sponsor())));
    }

    /** @return Collection<int, SponsorshipGrant> */
    private function selectableGrants(): Collection
    {
        return SponsorshipGrant::active()->forSponsor($this->sponsor())->where('mode', PromotionMode::Selected)->get();
    }

    /** @return Builder<Event> */
    private function eventsOfSponsor(): Builder
    {
        return $this->scopeEventsOfSponsor(Event::query());
    }

    /**
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    private function scopeEventsOfSponsor(Builder $query): Builder
    {
        $sponsor = $this->sponsor();

        return $sponsor instanceof Venue
            ? $query->where('venue_id', $sponsor->getKey())
            : $query->where('organizer_id', $sponsor->getKey());
    }

    /** Solo chi e' proprietario del committente puo' spendere a suo nome (`SponsorshipOrderPolicy::purchase`). */
    public function canPurchase(): bool
    {
        return Gate::allows('purchase', [SponsorshipOrder::class, $this->sponsor()]);
    }

    private function buyer(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function successUrl(): string
    {
        /* Le graffe restano letterali: le sostituisce Stripe, non `route()`. */
        return static::getUrl(tenant: $this->sponsor()).'?order={ORDER}&session_id={CHECKOUT_SESSION_ID}';
    }

    private function cancelUrl(): string
    {
        return static::getUrl(tenant: $this->sponsor()).'?order={ORDER}&cancelled=1';
    }

    private function redirectToCheckout(SponsorshipOrder $order): void
    {
        if ($order->checkout_url === null) {
            Notification::make()->title(__('promotions.orders.checkout_failed'))->danger()->send();

            return;
        }

        $this->redirect($order->checkout_url);
    }
}
