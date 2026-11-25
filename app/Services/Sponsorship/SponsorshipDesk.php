<?php

declare(strict_types=1);

namespace App\Services\Sponsorship;

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
use App\Services\Sponsorship\Payment\PaymentGatewayException;
use App\Support\Sponsorship\SponsorableEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

/**
 * La pagina «Sponsorizzazioni» di un committente, scritta una volta per il
 * pannello (`App\Filament\Shared\SponsorshipsPage`) e per l'API dell'app.
 *
 * Qui stanno le regole che prima vivevano dentro la pagina: quali eventi si
 * scelgono, l'invio (pagamento online o richiesta alla redazione) con il
 * tetto di cinque invii l'ora, le abilitazioni con i loro eventi, gli ultimi
 * ordini, riprova, annulla e ritira, la scelta degli eventi su un periodo già
 * attivo, lo stop di una campagna, le campagne della redazione e i totali.
 * Le regole più profonde restano dove erano: `SponsorshipOrders` (acquisto e
 * richiesta), `GrantCampaigns` (scelta degli eventi), `SponsorableEvents`
 * (quali eventi).
 *
 * **Chi può fare cosa** è lo stesso sul sito e nell'app: vedere, tutti i
 * membri; comprare, riprovare, annullare, ritirare, solo chi passa
 * `SponsorshipOrderPolicy::purchase`; mettere in evidenza un evento su un
 * periodo attivo e fermare una campagna, chi può modificare l'evento.
 *
 * **Lo stesso secchio.** Il tetto degli invii usa la chiave di sempre
 * (`sponsorship-request:{utente}`): chi invia dal sito e poi dall'app consuma
 * lo stesso limite.
 */
final class SponsorshipDesk
{
    /** Quante richieste può inviare una persona in un'ora, oltre al tetto delle richieste aperte. */
    public const REQUESTS_PER_HOUR = 5;

    /** Quanti eventi in più mostra «Mostra altri eventi». */
    public const EVENTS_STEP = 6;

    /** Il massimo di eventi fra cui scegliere in una volta. */
    public const EVENTS_MAX = 60;

    public const ORDERS_SHOWN = 20;

    public const CAMPAIGNS_PER_PAGE = 10;

    public function __construct(
        private readonly SponsorshipOrders $orders,
        private readonly GrantCampaigns $campaigns,
    ) {}

    /** Pagamento online con carta (Stripe configurato) o richiesta alla redazione. */
    public function paysOnline(): bool
    {
        return $this->orders->available();
    }

    /** Solo chi è proprietario del committente può spendere a suo nome (`SponsorshipOrderPolicy::purchase`). */
    public function canPurchase(User $user, Venue|Organizer $sponsor): bool
    {
        return Gate::forUser($user)->allows('purchase', [SponsorshipOrder::class, $sponsor]);
    }

    /**
     * Le durate di una posizione: le voci del listino, o le durate fisse con
     * il prezzo da concordare.
     *
     * @return list<array{key: string, months: int, amount_cents: int|null, price_id: int|null}>
     */
    public function durations(SponsorshipPlacement $placement): array
    {
        return $this->orders->durations($placement);
    }

    /** @return array{key: string, months: int, amount_cents: int|null, price_id: int|null}|null */
    public function duration(?SponsorshipPlacement $placement, ?string $key): ?array
    {
        if ($placement === null || $key === null) {
            return null;
        }

        foreach ($this->durations($placement) as $duration) {
            if ($duration['key'] === $key) {
                return $duration;
            }
        }

        return null;
    }

    /* ---------------------------------------------------------------
     * Gli eventi da scegliere.
     * --------------------------------------------------------------- */

    /**
     * Gli eventi sponsorizzabili del committente, cercando per titolo (al
     * massimo 80 caratteri); con la prossima data in `next_starts_at`.
     *
     * @return Builder<Event>
     */
    public function eventsQuery(Venue|Organizer $sponsor, string $search = ''): Builder
    {
        $search = trim($search);

        return SponsorableEvents::of($sponsor)
            ->when($search !== '', fn (Builder $q) => $q->where('events.title', 'like', '%'.addcslashes(mb_substr($search, 0, 80), '%_\\').'%'));
    }

    /**
     * I primi eventi in ordine di data.
     *
     * @return Collection<int, Event>
     */
    public function events(Venue|Organizer $sponsor, string $search, int $limit): Collection
    {
        return $this->eventsQuery($sponsor, $search)->with('media')->orderBy('next_starts_at')
            ->limit(max(1, min($limit, self::EVENTS_MAX)))->get();
    }

    /** Un evento del committente che si può sponsorizzare adesso; `null` altrimenti. */
    public function sponsorableEvent(Venue|Organizer $sponsor, ?int $id): ?Event
    {
        return $id === null || $id < 1 ? null : SponsorableEvents::of($sponsor)->find($id);
    }

    /* ---------------------------------------------------------------
     * L'invio: checkout o richiesta.
     * --------------------------------------------------------------- */

    /**
     * Invia la scelta: con Stripe e una voce di listino apre il pagamento,
     * altrimenti manda la richiesta alla redazione. L'ordine dei controlli è
     * quello di sempre: scelta completa, tetto orario della persona, poi
     * (solo per le richieste) il tetto delle richieste aperte.
     *
     * `$successUrl` e `$cancelUrl` contengono `{ORDER}` (lo sostituisce
     * `SponsorshipOrders`) e, per il successo, eventualmente
     * `{CHECKOUT_SESSION_ID}` (lo sostituisce Stripe).
     */
    public function submit(User $buyer, Venue|Organizer $sponsor, string $scope, ?int $eventId, SponsorshipPlacement $placement, string $durationKey, ?string $note, string $successUrl, string $cancelUrl): SponsorshipDeskResult
    {
        Gate::forUser($buyer)->authorize('purchase', [SponsorshipOrder::class, $sponsor]);

        $duration = $this->duration($placement, $durationKey);
        $event = $scope === 'event' ? $this->sponsorableEvent($sponsor, $eventId) : null;

        if ($duration === null || ! in_array($scope, ['event', 'all'], true) || ($scope === 'event' && $event === null)) {
            return new SponsorshipDeskResult(SponsorshipDeskOutcome::Incomplete);
        }

        $key = self::limitKey($buyer);
        if (RateLimiter::tooManyAttempts($key, self::REQUESTS_PER_HOUR)) {
            return new SponsorshipDeskResult(SponsorshipDeskOutcome::SlowDown);
        }
        RateLimiter::hit($key, 3600);

        $mode = $event !== null ? PromotionMode::Selected : PromotionMode::Automatic;
        $price = $duration['price_id'] !== null ? SponsorshipPrice::active()->find($duration['price_id']) : null;

        if ($price !== null && $this->paysOnline()) {
            try {
                $order = $this->orders->place($buyer, $sponsor, $price, $mode, $successUrl, $cancelUrl, $event);
            } catch (PaymentGatewayException) {
                return new SponsorshipDeskResult(SponsorshipDeskOutcome::CheckoutFailed);
            }

            return $order->checkout_url === null
                ? new SponsorshipDeskResult(SponsorshipDeskOutcome::CheckoutFailed, $order)
                : new SponsorshipDeskResult(SponsorshipDeskOutcome::CheckoutOpened, $order);
        }

        $open = SponsorshipOrder::query()->forSponsor($sponsor)->where('status', SponsorshipOrderStatus::Requested)->count();
        if ($open >= SponsorshipOrders::MAX_OPEN_REQUESTS) {
            return new SponsorshipDeskResult(SponsorshipDeskOutcome::TooManyRequests);
        }

        $order = $this->orders->request($buyer, $sponsor, $placement, $duration['months'], $price, $mode, $event, $note);

        return new SponsorshipDeskResult(SponsorshipDeskOutcome::Requested, $order);
    }

    /** La chiave del tetto degli invii, la stessa da quando stava nella pagina del pannello. */
    public static function limitKey(User $buyer): string
    {
        return 'sponsorship-request:'.$buyer->getKey();
    }

    /* ---------------------------------------------------------------
     * Un periodo già attivo: scegliere subito gli eventi.
     * --------------------------------------------------------------- */

    /**
     * Le abilitazioni attive «a scelta»: con queste si mettono in evidenza
     * eventi senza altre richieste. La prima scade per prima.
     *
     * @return Collection<int, SponsorshipGrant>
     */
    public function selectableGrants(Venue|Organizer $sponsor): Collection
    {
        return SponsorshipGrant::active()->forSponsor($sponsor)->where('mode', PromotionMode::Selected)->orderBy('ends_at')->get();
    }

    /**
     * Gli eventi già in evidenza con un'abilitazione.
     *
     * @return list<int>
     */
    public function activeEventIds(?int $grantId): array
    {
        return $grantId === null ? [] : Sponsorship::query()->where('sponsorship_grant_id', $grantId)
            ->where('status', SponsorshipStatus::Active)->pluck('event_id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * Mette in evidenza un evento con un'abilitazione attiva «a scelta» del
     * committente. `null` se l'abilitazione o l'evento non lo permettono;
     * il permesso sull'evento (`update`) lo controlla `GrantCampaigns`.
     */
    public function activate(User $user, Venue|Organizer $sponsor, ?int $grantId, int $eventId): ?Sponsorship
    {
        $grant = $grantId === null ? null : SponsorshipGrant::active()->forSponsor($sponsor)
            ->where('mode', PromotionMode::Selected)->find($grantId);
        $event = $this->sponsorableEvent($sponsor, $eventId);

        if ($grant === null || $event === null) {
            return null;
        }

        return $this->campaigns->choose($user, $grant, $event);
    }

    /* ---------------------------------------------------------------
     * Abilitazioni, ordini, campagne.
     * --------------------------------------------------------------- */

    /**
     * Le abilitazioni del committente, anche scadute, con le righe evento
     * (senza quelle nel cestino) già caricate per la scheda.
     *
     * @return Collection<int, SponsorshipGrant>
     */
    public function grants(Venue|Organizer $sponsor): Collection
    {
        return SponsorshipGrant::query()->forSponsor($sponsor)
            ->with(['venue.city', 'organizer.city', 'sponsorships' => fn ($rows) => $rows->with(['event.media', 'event.venue', 'event.city'])->orderByDesc('impressions')->orderBy('id')])
            ->orderByDesc('ends_at')->get();
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

    /**
     * La data da mostrare per ogni evento: la prossima, oppure l'ultima se
     * sono tutte passate. Una lettura per tutta la pagina.
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
        $found = DB::table('event_occurrences')->whereIn('event_id', array_values(array_unique($ids)))->whereNull('deleted_at')
            ->groupBy('event_id')
            ->selectRaw('event_id, MIN(CASE WHEN effective_ends_at > ? THEN starts_at END) AS next_start, MAX(starts_at) AS last_start', [now('UTC')->format('Y-m-d H:i:s')])
            ->get();

        foreach ($found as $row) {
            $date = $row->next_start ?? $row->last_start;
            if (is_numeric($row->event_id) && is_string($date)) {
                $dates[(int) $row->event_id] = $date;
            }
        }

        return $dates;
    }

    /**
     * Gli ultimi venti ordini e richieste.
     *
     * @return Collection<int, SponsorshipOrder>
     */
    public function orders(Venue|Organizer $sponsor): Collection
    {
        return SponsorshipOrder::query()->forSponsor($sponsor)->with('event.media')->latest('id')->limit(self::ORDERS_SHOWN)->get();
    }

    /** Un ordine del committente, eventualmente in uno stato preciso; 404 altrimenti. */
    public function order(Venue|Organizer $sponsor, int $id, ?SponsorshipOrderStatus $status = null): SponsorshipOrder
    {
        return SponsorshipOrder::query()->forSponsor($sponsor)
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status))->findOrFail($id);
    }

    /** Riapre la pagina di pagamento di un ordine in attesa. */
    public function retry(Venue|Organizer $sponsor, int $id, string $successUrl, string $cancelUrl): SponsorshipDeskResult
    {
        $order = $this->order($sponsor, $id, SponsorshipOrderStatus::PendingPayment);

        try {
            $this->orders->openCheckout($order, $successUrl, $cancelUrl);
        } catch (OrderNoLongerPayable) {
            return new SponsorshipDeskResult(SponsorshipDeskOutcome::NoLongerPayable, $order->refresh());
        } catch (PaymentGatewayException) {
            return new SponsorshipDeskResult(SponsorshipDeskOutcome::CheckoutFailed, $order);
        }

        /* La pagina aperta prima risultava già pagata: niente da riaprire. */
        if ($order->status === SponsorshipOrderStatus::Paid) {
            return new SponsorshipDeskResult(SponsorshipDeskOutcome::Paid, $order);
        }

        return $order->checkout_url === null
            ? new SponsorshipDeskResult(SponsorshipDeskOutcome::CheckoutFailed, $order)
            : new SponsorshipDeskResult(SponsorshipDeskOutcome::CheckoutOpened, $order);
    }

    /** Annulla un ordine in attesa di pagamento; se nel frattempo risulta pagato, lo dice. */
    public function cancel(Venue|Organizer $sponsor, int $id): SponsorshipDeskResult
    {
        $order = $this->order($sponsor, $id, SponsorshipOrderStatus::PendingPayment);

        try {
            $cancelled = $this->orders->cancel($order);
        } catch (PaymentGatewayException) {
            return new SponsorshipDeskResult(SponsorshipDeskOutcome::CancelFailed, $order);
        }

        return new SponsorshipDeskResult($cancelled ? SponsorshipDeskOutcome::Cancelled : SponsorshipDeskOutcome::Paid, $order->refresh());
    }

    /** Ritira una richiesta che la redazione non ha ancora deciso. */
    public function withdraw(Venue|Organizer $sponsor, int $id): SponsorshipDeskResult
    {
        $order = $this->order($sponsor, $id, SponsorshipOrderStatus::Requested);
        $this->orders->withdraw($order);

        return new SponsorshipDeskResult(SponsorshipDeskOutcome::Withdrawn, $order->refresh());
    }

    /**
     * Il ritorno dal checkout: chiuso senza pagare, oppure da riconciliare con
     * Stripe (se è pagato, è pagato anche se il webhook non è arrivato).
     * `null` per un ordine che non è del committente.
     */
    public function returnFromCheckout(Venue|Organizer $sponsor, int $id, bool $cancelled): ?SponsorshipDeskResult
    {
        $order = SponsorshipOrder::query()->forSponsor($sponsor)->find($id);

        if ($order === null) {
            return null;
        }

        if ($cancelled) {
            return new SponsorshipDeskResult(SponsorshipDeskOutcome::ReturnedCancelled, $order);
        }

        try {
            $this->orders->reconcile($order);
        } catch (PaymentGatewayException) {
            /* Stripe non risponde: il webhook arriverà comunque, e intanto
               si dice solo che il pagamento non risulta ancora. */
        }

        $order->refresh();

        return new SponsorshipDeskResult($order->status === SponsorshipOrderStatus::Paid ? SponsorshipDeskOutcome::Paid : SponsorshipDeskOutcome::ReturnedPending, $order);
    }

    /**
     * Una campagna che il committente può fermare: di una sua abilitazione «a
     * scelta», su un suo evento. 404 altrimenti.
     */
    public function stoppableCampaign(Venue|Organizer $sponsor, int $id): Sponsorship
    {
        return Sponsorship::query()->whereHas('event', fn (Builder $q) => $this->scopeEventsOfSponsor($q, $sponsor))
            ->whereHas('grant', fn (Builder $q) => $q->where('mode', PromotionMode::Selected)->forSponsor($sponsor))->findOrFail($id);
    }

    /** Ferma (mette in pausa) una campagna: chi può modificare l'evento, come sul sito. */
    public function stop(User $user, Venue|Organizer $sponsor, int $id): Sponsorship
    {
        $campaign = $this->stoppableCampaign($sponsor, $id);
        Gate::forUser($user)->authorize('update', $campaign->event);
        $campaign->update(['status' => SponsorshipStatus::Paused]);

        return $campaign;
    }

    /**
     * Le campagne della redazione sui propri eventi, cioè quelle senza
     * abilitazione: le righe delle abilitazioni stanno nella scheda della loro
     * abilitazione. `$page` nullo lascia decidere la pagina a chi pagina
     * (Livewire nel pannello).
     *
     * @return LengthAwarePaginator<int, Sponsorship>
     */
    public function editorialCampaigns(Venue|Organizer $sponsor, ?int $page = null): LengthAwarePaginator
    {
        return $this->campaignsQuery($sponsor)->whereNull('sponsorship_grant_id')->with(['event.media', 'event.city'])
            ->orderByDesc('ends_at')->paginate(self::CAMPAIGNS_PER_PAGE, ['*'], 'page', $page);
    }

    /**
     * I totali di tutte le sponsorizzazioni sui propri eventi, comprese le
     * righe tolte: le visualizzazioni date restano date, come nell'avanzamento.
     *
     * @return array{impressions: int, clicks: int}
     */
    public function totals(Venue|Organizer $sponsor): array
    {
        $totals = $this->campaignsQuery($sponsor)->withTrashed()
            ->selectRaw('COALESCE(SUM(impressions), 0) as impressions, COALESCE(SUM(clicks), 0) as clicks')->first();

        return ['impressions' => (int) $totals?->impressions, 'clicks' => (int) $totals?->clicks];
    }

    /** Il fuso della città del committente: date e scadenze si leggono lì, non in UTC. */
    public static function timezone(Venue|Organizer $sponsor): string
    {
        $timezone = $sponsor->city?->timezone;

        return is_string($timezone) && $timezone !== '' ? $timezone : 'Europe/Rome';
    }

    /** @return Builder<Sponsorship> */
    private function campaignsQuery(Venue|Organizer $sponsor): Builder
    {
        return Sponsorship::query()
            ->whereHas('event', fn (Builder $q) => $this->scopeEventsOfSponsor($q, $sponsor))
            ->where(fn (Builder $q) => $q->whereNull('sponsorship_grant_id')->orWhereHas('grant', fn (Builder $g) => $g->forSponsor($sponsor)));
    }

    /**
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    private function scopeEventsOfSponsor(Builder $query, Venue|Organizer $sponsor): Builder
    {
        return $sponsor instanceof Venue
            ? $query->where('venue_id', $sponsor->getKey())
            : $query->where('organizer_id', $sponsor->getKey());
    }
}
