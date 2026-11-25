<?php

declare(strict_types=1);

namespace App\Filament\Shared;

use App\Enums\SponsorshipPlacement;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\Sponsorship;
use App\Models\SponsorshipGrant;
use App\Models\SponsorshipOrder;
use App\Models\User;
use App\Models\Venue;
use App\Services\Sponsorship\SponsorshipDesk;
use App\Services\Sponsorship\SponsorshipDeskOutcome;
use App\Services\Sponsorship\SponsorshipProgress;
use App\Services\Sponsorship\UnitProgress;
use App\Support\Sponsorship\SponsorableEvents;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Gate;
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
 * La pagina disegna e autorizza; le regole stanno in `SponsorshipDesk`, che
 * chiama anche l'API dell'app, e sotto di lui in `SponsorshipOrders`
 * (acquisto e richiesta), `GrantCampaigns` (scelta degli eventi) e
 * `SponsorableEvents` (quali eventi): ogni valore che arriva da Livewire
 * viene ricontrollato li'.
 */
abstract class SponsorshipsPage extends Page
{
    use WithPagination;

    private const EVENTS_PAGE = SponsorshipDesk::EVENTS_STEP;

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

        $result = $this->desk()->returnFromCheckout($this->sponsor(), $orderId, request()->boolean('cancelled'));

        if ($result === null) {
            return;
        }

        $result->outcome === SponsorshipDeskOutcome::Paid
            ? Notification::make()->title($result->outcome->message())->success()->send()
            : Notification::make()->title($result->outcome->message())->warning()->send();
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
        return $this->desk()->paysOnline();
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
        $this->eventLimit = min($this->eventLimit + self::EVENTS_PAGE, SponsorshipDesk::EVENTS_MAX);
    }

    public function selectEvent(int $id): void
    {
        $event = $this->desk()->sponsorableEvent($this->sponsor(), $id);

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

        return $placement === null ? [] : $this->desk()->durations($placement);
    }

    /** @return array{key: string, months: int, amount_cents: int|null, price_id: int|null}|null */
    public function chosenDuration(): ?array
    {
        return $this->desk()->duration(SponsorshipPlacement::tryFrom((string) $this->placement), $this->duration);
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
        return $this->desk()->events($this->sponsor(), $this->search, $this->eventLimit);
    }

    public function hasMoreEvents(): bool
    {
        return $this->desk()->eventsQuery($this->sponsor(), $this->search)->count() > $this->eventLimit;
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

        $result = $this->desk()->submit($this->buyer(), $this->sponsor(), $this->scope, $this->eventId,
            SponsorshipPlacement::from((string) $this->placement), (string) $this->duration, $this->note, $this->successUrl(), $this->cancelUrl());

        match ($result->outcome) {
            SponsorshipDeskOutcome::CheckoutOpened => $this->redirectToCheckout($result->order),
            SponsorshipDeskOutcome::Requested => $this->requestSent(),
            SponsorshipDeskOutcome::SlowDown, SponsorshipDeskOutcome::CheckoutFailed => Notification::make()->title($result->outcome->message())->danger()->send(),
            default => Notification::make()->title($result->outcome->message())->warning()->send(),
        };
    }

    private function requestSent(): void
    {
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
        if ($this->desk()->activate($this->buyer(), $this->sponsor(), $this->grantId, $eventId) === null) {
            Notification::make()->title(__('promotions.request.event_not_available'))->warning()->send();

            return;
        }

        Notification::make()->title(__('promotions.success'))->success()->send();
    }

    /** @return Collection<int, SponsorshipGrant> */
    public function selectableGrants(): Collection
    {
        return $this->desk()->selectableGrants($this->sponsor());
    }

    /**
     * Gli eventi gia' in evidenza con l'abilitazione scelta.
     *
     * @return list<int>
     */
    public function activeEventIds(): array
    {
        return $this->desk()->activeEventIds($this->grantId);
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
        return $this->desk()->grants($this->sponsor());
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
        return $this->desk()->grantRows($grant);
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
        return $this->desk()->eventDates($rows);
    }

    /** @return Collection<int, SponsorshipOrder> */
    public function orders(): Collection
    {
        return $this->desk()->orders($this->sponsor());
    }

    public function retry(int $id): void
    {
        Gate::authorize('purchase', [SponsorshipOrder::class, $this->sponsor()]);
        $result = $this->desk()->retry($this->sponsor(), $id, $this->successUrl(), $this->cancelUrl());

        match ($result->outcome) {
            SponsorshipDeskOutcome::CheckoutOpened => $this->redirectToCheckout($result->order),
            SponsorshipDeskOutcome::Paid => Notification::make()->title($result->outcome->message())->success()->send(),
            SponsorshipDeskOutcome::NoLongerPayable => Notification::make()->title($result->outcome->message())->warning()->send(),
            default => Notification::make()->title($result->outcome->message())->danger()->send(),
        };
    }

    public function cancelOrder(int $id): void
    {
        Gate::authorize('purchase', [SponsorshipOrder::class, $this->sponsor()]);
        $result = $this->desk()->cancel($this->sponsor(), $id);

        $result->outcome === SponsorshipDeskOutcome::CancelFailed
            ? Notification::make()->title($result->outcome->message())->danger()->send()
            : Notification::make()->title($result->outcome->message())->success()->send();
    }

    public function withdraw(int $id): void
    {
        Gate::authorize('purchase', [SponsorshipOrder::class, $this->sponsor()]);
        $this->desk()->withdraw($this->sponsor(), $id);
        Notification::make()->title(__('promotions.request.withdrawn'))->success()->send();
    }

    public function stop(int $id): void
    {
        $this->desk()->stop($this->buyer(), $this->sponsor(), $id);
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
        return $this->desk()->editorialCampaigns($this->sponsor());
    }

    /**
     * I totali di tutte le sponsorizzazioni sui propri eventi, comprese le
     * righe tolte: le visualizzazioni date restano date, come nell'avanzamento.
     *
     * @return array{impressions: int, clicks: int}
     */
    public function totals(): array
    {
        return $this->desk()->totals($this->sponsor());
    }

    /** Il fuso della citta' del committente: date e scadenze si leggono li', non in UTC. */
    public function timezone(): string
    {
        return SponsorshipDesk::timezone($this->sponsor());
    }

    public function isVenue(): bool
    {
        return $this->sponsor() instanceof Venue;
    }

    /** Solo chi e' proprietario del committente puo' spendere a suo nome (`SponsorshipOrderPolicy::purchase`). */
    public function canPurchase(): bool
    {
        return $this->desk()->canPurchase($this->buyer(), $this->sponsor());
    }

    private function desk(): SponsorshipDesk
    {
        return app(SponsorshipDesk::class);
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

    private function redirectToCheckout(?SponsorshipOrder $order): void
    {
        if ($order?->checkout_url === null) {
            Notification::make()->title(__('promotions.orders.checkout_failed'))->danger()->send();

            return;
        }

        $this->redirect($order->checkout_url);
    }
}
