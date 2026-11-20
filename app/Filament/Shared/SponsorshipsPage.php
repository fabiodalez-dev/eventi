<?php

declare(strict_types=1);

namespace App\Filament\Shared;

use App\Enums\PromotionMode;
use App\Enums\SponsorshipOrderStatus;
use App\Enums\SponsorshipPlacement;
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
use App\Services\Sponsorship\OrderNoLongerPayable;
use App\Services\Sponsorship\Payment\PaymentGatewayException;
use App\Services\Sponsorship\SponsorshipOrders;
use App\Services\Sponsorship\SponsorshipProgress;
use App\Services\Sponsorship\UnitProgress;
use App\Support\Sponsorship\SponsorableEvents;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\WithPagination;

/**
 * Sponsorizzazioni dal pannello di un committente: come funziona, la scelta
 * a card di evento, formato e durata, le richieste e gli ordini, le campagne.
 *
 * Una classe per i due pannelli perche' locale e organizzatore fanno le
 * stesse cose; cambia solo chi e' il committente (`sponsor()`), e quello lo
 * dicono le sottoclassi.
 *
 * **Si puo' sempre cominciare.** Con Stripe configurato l'ultimo passo apre il
 * pagamento; senza, invia una richiesta alla redazione. Prima la pagina, senza
 * Stripe, mostrava solo elenchi vuoti e nessun pulsante: «qui non si puo'
 * sponsorizzare».
 *
 * La pagina disegna e autorizza; le regole stanno in `SponsorshipOrders`
 * (acquisto e richiesta), `GrantCampaigns` (scelta degli eventi) e
 * `SponsorableEvents` (quali eventi): ogni valore che arriva da Livewire
 * viene ricontrollato li'.
 */
abstract class SponsorshipsPage extends Page
{
    use WithPagination;

    /** Quante richieste puo' inviare una persona in un'ora, oltre al tetto delle richieste aperte. */
    private const REQUESTS_PER_HOUR = 5;

    private const EVENTS_PAGE = 6;

    /** Quanti eventi mostra la scheda di un'abilitazione prima di «mostra gli altri». */
    public const GRANT_EVENTS_SHOWN = 5;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?int $navigationSort = 7;

    protected static ?string $slug = 'sponsorizzazioni';

    protected string $view = 'filament.shared.sponsorships';

    /** «event» (un evento scelto qui) oppure «all» (tutti gli eventi, anche i prossimi). */
    public string $scope = 'event';

    public ?int $eventId = null;

    public ?string $placement = null;

    public ?string $duration = null;

    public string $note = '';

    public string $search = '';

    public int $eventLimit = self::EVENTS_PAGE;

    /** L'abilitazione attiva con cui si stanno scegliendo eventi da mettere in evidenza subito. */
    public ?int $grantId = null;

    /**
     * Le abilitazioni di cui la scheda mostra tutti gli eventi, non solo i
     * primi. Serve solo a disegnare: le schede sono sempre quelle del
     * committente (`grants()`), un id altrui qui non apre niente.
     *
     * @var list<int>
     */
    public array $openGrants = [];

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
        return __('promotions.page.lead');
    }

    /**
     * Il ritorno dal checkout: `?order=ID&session_id=…` dopo aver pagato,
     * `?order=ID&cancelled=1` dopo aver chiuso la pagina di Stripe.
     */
    public function mount(): void
    {
        $this->grantId = $this->selectableGrants()->first()?->getKey();

        /* `?event=ID` dai collegamenti delle altre pagine del pannello: l'evento
           arriva gia' scelto, se e' del committente e sponsorizzabile. */
        $eventId = request()->integer('event');
        if ($eventId > 0 && SponsorableEvents::of($this->sponsor())->whereKey($eventId)->exists()) {
            $this->eventId = $eventId;
        }

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
        $analytics = $this->analyticsUrl();

        return $analytics === null ? [] : [
            Action::make('analytics')->label(__('sponsorships.venue.analytics'))->icon(Heroicon::OutlinedChartBar)->url($analytics)->color('gray'),
        ];
    }

    /* ---------------------------------------------------------------
     * La scelta: evento, formato, durata, riepilogo.
     * --------------------------------------------------------------- */

    /** Pagamento online con carta (Stripe configurato) o richiesta alla redazione. */
    public function paysOnline(): bool
    {
        return app(SponsorshipOrders::class)->available();
    }

    public function updatedSearch(): void
    {
        $this->eventLimit = self::EVENTS_PAGE;
    }

    public function updatedScope(string $value): void
    {
        if (! in_array($value, ['event', 'all'], true)) {
            $this->scope = 'event';
        }
    }

    public function showMoreEvents(): void
    {
        $this->eventLimit = min($this->eventLimit + self::EVENTS_PAGE, 60);
    }

    public function selectEvent(int $id): void
    {
        $event = SponsorableEvents::of($this->sponsor())->find($id);

        if ($event === null) {
            Notification::make()->title(__('promotions.request.event_not_available'))->warning()->send();

            return;
        }

        $this->eventId = $event->getKey();
        $this->scope = 'event';
    }

    public function selectPlacement(string $placement): void
    {
        $this->placement = SponsorshipPlacement::tryFrom($placement)?->value;
        $keys = $this->placement === null ? [] : array_column($this->durations(), 'key');

        if (! in_array($this->duration, $keys, true)) {
            $this->duration = count($keys) > 0 ? $keys[0] : null;
        }
    }

    public function selectDuration(string $key): void
    {
        $this->duration = in_array($key, array_column($this->durations(), 'key'), true) ? $key : null;
    }

    /** @return list<array{key: string, months: int, amount_cents: int|null, price_id: int|null}> */
    public function durations(): array
    {
        $placement = SponsorshipPlacement::tryFrom((string) $this->placement);

        return $placement === null ? [] : app(SponsorshipOrders::class)->durations($placement);
    }

    /** @return array{key: string, months: int, amount_cents: int|null, price_id: int|null}|null */
    public function chosenDuration(): ?array
    {
        foreach ($this->durations() as $duration) {
            if ($duration['key'] === $this->duration) {
                return $duration;
            }
        }

        return null;
    }

    public function chosenEvent(): ?Event
    {
        return $this->scope === 'event' && $this->eventId !== null
            ? SponsorableEvents::of($this->sponsor())->with('media')->find($this->eventId)
            : null;
    }

    public function readyToSubmit(): bool
    {
        return ($this->scope === 'all' || $this->chosenEvent() !== null)
            && SponsorshipPlacement::tryFrom((string) $this->placement) !== null
            && $this->chosenDuration() !== null;
    }

    /**
     * Gli eventi fra cui scegliere, cercando per titolo; i primi in ordine di
     * data.
     *
     * @return Collection<int, Event>
     */
    public function sponsorableEvents(): Collection
    {
        return $this->eventsQuery()->with('media')->orderBy('next_starts_at')->limit($this->eventLimit)->get();
    }

    public function hasMoreEvents(): bool
    {
        return $this->eventsQuery()->count() > $this->eventLimit;
    }

    public function sponsorableCount(): int
    {
        return SponsorableEvents::of($this->sponsor())->count();
    }

    /**
     * @param  Collection<int, Event>  $events
     * @return list<int>
     */
    public function sponsoredIds(Collection $events): array
    {
        return SponsorableEvents::alreadySponsored(array_map('intval', $events->modelKeys()));
    }

    public function submitOrder(): void
    {
        Gate::authorize('purchase', [SponsorshipOrder::class, $this->sponsor()]);
        $this->validate([
            'scope' => ['required', 'in:event,all'],
            'placement' => ['required', 'in:'.implode(',', SponsorshipPlacement::values())],
            'duration' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $placement = SponsorshipPlacement::from((string) $this->placement);
        $duration = $this->chosenDuration();
        $event = $this->scope === 'event' ? $this->chosenEvent() : null;

        if ($duration === null || ($this->scope === 'event' && $event === null)) {
            Notification::make()->title(__('promotions.request.incomplete'))->warning()->send();

            return;
        }

        $key = 'sponsorship-request:'.$this->buyer()->getKey();
        if (RateLimiter::tooManyAttempts($key, self::REQUESTS_PER_HOUR)) {
            Notification::make()->title(__('promotions.request.slow_down'))->danger()->send();

            return;
        }
        RateLimiter::hit($key, 3600);

        $mode = $event !== null ? PromotionMode::Selected : PromotionMode::Automatic;
        $price = $duration['price_id'] !== null ? SponsorshipPrice::active()->find($duration['price_id']) : null;

        if ($price !== null && $this->paysOnline()) {
            try {
                $order = app(SponsorshipOrders::class)->place($this->buyer(), $this->sponsor(), $price, $mode, $this->successUrl(), $this->cancelUrl(), $event);
            } catch (PaymentGatewayException) {
                Notification::make()->title(__('promotions.orders.checkout_failed'))->danger()->send();

                return;
            }

            $this->redirectToCheckout($order);

            return;
        }

        $open = SponsorshipOrder::query()->forSponsor($this->sponsor())->where('status', SponsorshipOrderStatus::Requested)->count();
        if ($open >= SponsorshipOrders::MAX_OPEN_REQUESTS) {
            Notification::make()->title(__('promotions.request.too_many', ['max' => SponsorshipOrders::MAX_OPEN_REQUESTS]))->warning()->send();

            return;
        }

        app(SponsorshipOrders::class)->request($this->buyer(), $this->sponsor(), $placement, $duration['months'], $price, $mode, $event, $this->note);
        Notification::make()->title(__('promotions.request.sent'))->body(__('promotions.request.sent_body'))->success()->send();
        $this->reset(['eventId', 'placement', 'duration', 'note', 'search']);
        $this->scope = 'event';
    }

    /* ---------------------------------------------------------------
     * Un periodo gia' attivo: scegliere subito gli eventi.
     * --------------------------------------------------------------- */

    public function chooseGrant(int $id): void
    {
        $this->grantId = $this->selectableGrants()->firstWhere('id', $id)?->getKey();
    }

    public function activate(int $eventId): void
    {
        $grant = $this->grantId === null ? null : SponsorshipGrant::active()->forSponsor($this->sponsor())
            ->where('mode', PromotionMode::Selected)->find($this->grantId);
        $event = SponsorableEvents::of($this->sponsor())->find($eventId);

        if ($grant === null || $event === null) {
            Notification::make()->title(__('promotions.request.event_not_available'))->warning()->send();

            return;
        }

        app(GrantCampaigns::class)->choose($this->buyer(), $grant, $event);
        Notification::make()->title(__('promotions.success'))->success()->send();
    }

    /** @return Collection<int, SponsorshipGrant> */
    public function selectableGrants(): Collection
    {
        return SponsorshipGrant::active()->forSponsor($this->sponsor())->where('mode', PromotionMode::Selected)->orderBy('ends_at')->get();
    }

    /**
     * Gli eventi gia' in evidenza con l'abilitazione scelta.
     *
     * @return list<int>
     */
    public function activeEventIds(): array
    {
        return $this->grantId === null ? [] : Sponsorship::query()->where('sponsorship_grant_id', $this->grantId)
            ->where('status', SponsorshipStatus::Active)->pluck('event_id')->map(fn ($id): int => (int) $id)->all();
    }

    /* ---------------------------------------------------------------
     * Richieste, ordini, abilitazioni e campagne.
     * --------------------------------------------------------------- */

    /**
     * Le abilitazioni del committente, anche scadute, con le righe evento
     * (senza quelle nel cestino) già caricate per la scheda.
     *
     * @return Collection<int, SponsorshipGrant>
     */
    public function grants(): Collection
    {
        return SponsorshipGrant::query()->forSponsor($this->sponsor())
            ->with(['venue.city', 'organizer.city', 'sponsorships' => fn ($rows) => $rows->with(['event.media', 'event.venue', 'event.city'])->orderByDesc('impressions')->orderBy('id')])
            ->orderByDesc('ends_at')->get();
    }

    /**
     * L'avanzamento di ogni abilitazione: barra, stato e frase.
     *
     * @param  Collection<int, SponsorshipGrant>  $grants
     * @return array<int, UnitProgress>
     */
    public function grantProgress(Collection $grants): array
    {
        return app(SponsorshipProgress::class)->grants($grants);
    }

    /**
     * L'avanzamento delle campagne della redazione, ognuna un'unità a sé.
     *
     * @param  iterable<Sponsorship>  $campaigns
     * @return array<int, UnitProgress>
     */
    public function campaignProgress(iterable $campaigns): array
    {
        return app(SponsorshipProgress::class)->campaigns($campaigns);
    }

    /**
     * Le righe evento di un'abilitazione nell'ordine della scheda: quelle
     * con un evento ancora leggibile, le più viste in cima.
     *
     * @return SupportCollection<int, Sponsorship>
     */
    public function grantRows(SponsorshipGrant $grant): SupportCollection
    {
        return $grant->sponsorships->filter(fn (Sponsorship $row): bool => $row->event !== null)->values();
    }

    public function toggleGrantEvents(int $id): void
    {
        $this->openGrants = in_array($id, $this->openGrants, true)
            ? array_values(array_diff($this->openGrants, [$id]))
            : [...$this->openGrants, $id];
    }

    /**
     * La data da mostrare per ogni evento: la prossima, oppure l'ultima se
     * sono tutte passate. Una lettura per tutta la pagina, invece di una per
     * riga dentro `event-row`.
     *
     * @param  iterable<Sponsorship>  $rows
     * @return array<int, string>
     */
    public function eventDates(iterable $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row->event_id;
        }

        if ($ids === []) {
            return [];
        }

        $dates = [];
        $rows = DB::table('event_occurrences')->whereIn('event_id', array_values(array_unique($ids)))->whereNull('deleted_at')
            ->groupBy('event_id')
            ->selectRaw('event_id, MIN(CASE WHEN effective_ends_at > ? THEN starts_at END) AS next_start, MAX(starts_at) AS last_start', [now('UTC')->format('Y-m-d H:i:s')])
            ->get();

        foreach ($rows as $row) {
            $date = $row->next_start ?? $row->last_start;
            if (is_numeric($row->event_id) && is_string($date)) {
                $dates[(int) $row->event_id] = $date;
            }
        }

        return $dates;
    }

    /** @return Collection<int, SponsorshipOrder> */
    public function orders(): Collection
    {
        return SponsorshipOrder::query()->forSponsor($this->sponsor())->with('event.media')->latest('id')->limit(20)->get();
    }

    public function retry(int $id): void
    {
        Gate::authorize('purchase', [SponsorshipOrder::class, $this->sponsor()]);
        $order = SponsorshipOrder::query()->forSponsor($this->sponsor())
            ->where('status', SponsorshipOrderStatus::PendingPayment)->findOrFail($id);

        try {
            app(SponsorshipOrders::class)->openCheckout($order, $this->successUrl(), $this->cancelUrl());
        } catch (OrderNoLongerPayable) {
            Notification::make()->title(__('promotions.orders.no_longer_payable'))->warning()->send();

            return;
        } catch (PaymentGatewayException) {
            Notification::make()->title(__('promotions.orders.checkout_failed'))->danger()->send();

            return;
        }

        /* La pagina aperta prima risultava gia' pagata: niente da riaprire. */
        if ($order->status === SponsorshipOrderStatus::Paid) {
            Notification::make()->title(__('promotions.orders.returned_paid'))->success()->send();

            return;
        }

        $this->redirectToCheckout($order);
    }

    public function cancelOrder(int $id): void
    {
        Gate::authorize('purchase', [SponsorshipOrder::class, $this->sponsor()]);
        $order = SponsorshipOrder::query()->forSponsor($this->sponsor())
            ->where('status', SponsorshipOrderStatus::PendingPayment)->findOrFail($id);

        try {
            $cancelled = app(SponsorshipOrders::class)->cancel($order);
        } catch (PaymentGatewayException) {
            Notification::make()->title(__('promotions.orders.cancel_failed'))->danger()->send();

            return;
        }

        $cancelled
            ? Notification::make()->title(__('promotions.orders.cancelled'))->success()->send()
            : Notification::make()->title(__('promotions.orders.returned_paid'))->success()->send();
    }

    public function withdraw(int $id): void
    {
        Gate::authorize('purchase', [SponsorshipOrder::class, $this->sponsor()]);
        $order = SponsorshipOrder::query()->forSponsor($this->sponsor())
            ->where('status', SponsorshipOrderStatus::Requested)->findOrFail($id);
        app(SponsorshipOrders::class)->withdraw($order);
        Notification::make()->title(__('promotions.request.withdrawn'))->success()->send();
    }

    public function stop(int $id): void
    {
        $campaign = Sponsorship::query()->whereHas('event', fn (Builder $q) => $this->scopeEventsOfSponsor($q))
            ->whereHas('grant', fn (Builder $q) => $q->where('mode', PromotionMode::Selected)->forSponsor($this->sponsor()))->findOrFail($id);
        Gate::authorize('update', $campaign->event);
        $campaign->update(['status' => SponsorshipStatus::Paused]);
        Notification::make()->title(__('promotions.stopped'))->success()->send();
    }

    /**
     * Le campagne della redazione sui propri eventi, cioè quelle senza
     * abilitazione: le righe delle abilitazioni stanno nella scheda della
     * loro abilitazione.
     *
     * @return LengthAwarePaginator<int, Sponsorship>
     */
    public function sponsorships(): LengthAwarePaginator
    {
        return $this->campaignsQuery()->whereNull('sponsorship_grant_id')->with(['event.media', 'event.city'])->orderByDesc('ends_at')->paginate(10);
    }

    /**
     * I totali di tutte le sponsorizzazioni sui propri eventi, comprese le
     * righe tolte: le visualizzazioni date restano date, come nell'avanzamento.
     *
     * @return array{impressions: int, clicks: int}
     */
    public function totals(): array
    {
        $totals = $this->campaignsQuery()->withTrashed()
            ->selectRaw('COALESCE(SUM(impressions), 0) as impressions, COALESCE(SUM(clicks), 0) as clicks')->first();

        return ['impressions' => (int) $totals?->impressions, 'clicks' => (int) $totals?->clicks];
    }

    /** Il fuso della citta' del committente: date e scadenze si leggono li', non in UTC. */
    public function timezone(): string
    {
        $sponsor = $this->sponsor();
        $timezone = $sponsor->city?->timezone;

        return is_string($timezone) && $timezone !== '' ? $timezone : 'Europe/Rome';
    }

    public function isVenue(): bool
    {
        return $this->sponsor() instanceof Venue;
    }

    /** Solo chi e' proprietario del committente puo' spendere a suo nome (`SponsorshipOrderPolicy::purchase`). */
    public function canPurchase(): bool
    {
        return Gate::allows('purchase', [SponsorshipOrder::class, $this->sponsor()]);
    }

    /** @return Builder<Sponsorship> */
    private function campaignsQuery(): Builder
    {
        return Sponsorship::query()
            ->whereHas('event', fn (Builder $q) => $this->scopeEventsOfSponsor($q))
            ->where(fn (Builder $q) => $q->whereNull('sponsorship_grant_id')->orWhereHas('grant', fn (Builder $g) => $g->forSponsor($this->sponsor())));
    }

    /** @return Builder<Event> */
    private function eventsQuery(): Builder
    {
        $search = trim($this->search);

        return SponsorableEvents::of($this->sponsor())
            ->when($search !== '', fn (Builder $q) => $q->where('events.title', 'like', '%'.addcslashes(mb_substr($search, 0, 80), '%_\\').'%'));
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
