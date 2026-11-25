<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\ApiErrorCode;
use App\Enums\SponsorshipPlacement;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Management\SponsorshipDeskRequest;
use App\Http\Resources\V1\Management\SponsorshipPayload;
use App\Models\Organizer;
use App\Models\Sponsorship;
use App\Models\SponsorshipOrder;
use App\Models\Venue;
use App\Services\Sponsorship\SponsorshipDesk;
use App\Services\Sponsorship\SponsorshipDeskOutcome;
use App\Services\Sponsorship\SponsorshipDeskResult;
use App\Services\Sponsorship\SponsorshipOrders;
use App\Services\Sponsorship\SponsorshipProgress;
use App\Support\Sponsorship\SponsorableEvents;
use Illuminate\Http\JsonResponse;

/**
 * La pagina «Sponsorizzazioni» dei due pannelli nell'app (P6, W-SP-01…13):
 * come funziona, un periodo già attivo su cui mettere in evidenza gli eventi,
 * la scelta in quattro passi con invio (pagamento con Stripe o richiesta alla
 * redazione), richieste e ordini, le schede delle abilitazioni con
 * l'avanzamento, le campagne della redazione e i totali.
 *
 * Le regole sono quelle della pagina, perché passano dallo stesso servizio
 * (`SponsorshipDesk`): stesso tetto di cinque invii l'ora sulla stessa chiave,
 * stesso tetto delle richieste aperte, stessi permessi.
 *
 * **Il pagamento si fa nel browser** (decisione 1): l'invio risponde con
 * `checkout_url`, l'app la apre in una scheda del browser, e Stripe riporta a
 * `/app/sponsorizzazioni/ritorno`, un App Link che riapre l'app; l'app chiama
 * allora `reconcile` per sapere se è pagato.
 */
final class SponsorshipController extends Controller
{
    /** La pagina intera, in una lettura. */
    public function index(SponsorshipDeskRequest $request, SponsorshipDesk $desk, SponsorshipProgress $progress): JsonResponse
    {
        $sponsor = $request->sponsor();
        $actor = $request->actor();
        $tz = SponsorshipDesk::timezone($sponsor);
        $canPurchase = $desk->canPurchase($actor, $sponsor);
        $online = $desk->paysOnline();
        $isVenue = $sponsor instanceof Venue;

        $grants = $desk->grants($sponsor);
        $grantProgress = $progress->grants($grants);
        $campaigns = $desk->editorialCampaigns($sponsor, $request->integer('campaigns_page', 1));
        $campaignProgress = $progress->campaigns($campaigns->getCollection());
        $dates = $desk->eventDates($grants->flatMap(fn ($g) => $g->sponsorships)->concat($campaigns->getCollection()));

        return response()->json(['data' => [
            'subject' => ['type' => $isVenue ? 'venue' : 'organizer', 'id' => (int) $sponsor->getKey(), 'name' => (string) $sponsor->name, 'timezone' => $tz],
            'can_purchase' => $canPurchase,
            'pays_online' => $online,
            'how' => [
                'title' => __('promotions.page.how_title'),
                'steps' => [__('promotions.page.how_step_1'), __('promotions.page.how_step_2'), __($online ? 'promotions.page.how_step_3_online' : 'promotions.page.how_step_3_request')],
                'disclosure' => __('promotions.page.how_disclosure'),
            ],
            'not_owner' => $canPurchase ? null : [
                'title' => __('promotions.page.not_owner_title'),
                'body' => __($isVenue ? 'promotions.page.not_owner_body_venue' : 'promotions.page.not_owner_body_organizer'),
            ],
            'limits' => [
                'requests_per_hour' => SponsorshipDesk::REQUESTS_PER_HOUR,
                'max_open_requests' => SponsorshipOrders::MAX_OPEN_REQUESTS,
                'note_max' => 1000,
                'events_max' => SponsorshipDesk::EVENTS_MAX,
            ],
            'sponsorable_count' => SponsorableEvents::of($sponsor)->count(),
            'selectable_grants' => $desk->selectableGrants($sponsor)->map(fn ($grant): array => [
                'id' => (int) $grant->getKey(),
                'placement' => SponsorshipPayload::placement($grant->placement),
                'ends_at' => $grant->ends_at->copy()->setTimezone($tz)->toIso8601String(),
                'active_event_ids' => $desk->activeEventIds((int) $grant->getKey()),
            ])->values()->all(),
            'orders' => $desk->orders($sponsor)->map(fn (SponsorshipOrder $order): array => SponsorshipPayload::order($order, $tz, $canPurchase))->values()->all(),
            'measures' => __('sponsorship-panel.units.measures'),
            'totals' => $desk->totals($sponsor),
            'grants' => $grants->map(fn ($grant): array => SponsorshipPayload::grant($grant, $grantProgress[$grant->id], $desk->grantRows($grant), $dates, $tz, $actor, $isVenue))->values()->all(),
            'campaigns' => $campaigns->getCollection()->map(fn (Sponsorship $c): array => SponsorshipPayload::editorialCampaign($c, $campaignProgress[$c->id], $dates, $tz))->values()->all(),
            'campaigns_next_page' => $campaigns->hasMorePages() ? $campaigns->currentPage() + 1 : null,
        ]]);
    }

    /** Altre campagne della redazione (dieci per pagina), oltre a quelle della prima lettura. */
    public function campaigns(SponsorshipDeskRequest $request, SponsorshipDesk $desk, SponsorshipProgress $progress): JsonResponse
    {
        $sponsor = $request->sponsor();
        $tz = SponsorshipDesk::timezone($sponsor);
        $page = $desk->editorialCampaigns($sponsor, $request->integer('page', 1));
        $unitProgress = $progress->campaigns($page->getCollection());
        $dates = $desk->eventDates($page->getCollection());

        return response()->json([
            'data' => $page->getCollection()->map(fn (Sponsorship $c): array => SponsorshipPayload::editorialCampaign($c, $unitProgress[$c->id], $dates, $tz))->values()->all(),
            'meta' => ['next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null, 'total' => $page->total()],
        ]);
    }

    /**
     * Gli eventi da mettere in evidenza: pubblicati, con una data in
     * programma, cercando nel titolo. Con `grant` dice anche quali sono già in
     * evidenza con quel periodo attivo.
     */
    public function events(SponsorshipDeskRequest $request, SponsorshipDesk $desk): JsonResponse
    {
        $sponsor = $request->sponsor();
        $tz = SponsorshipDesk::timezone($sponsor);
        $search = (string) $request->validated('q', '');
        $limit = $request->integer('limit', 20);
        $events = $desk->events($sponsor, $search, $limit);
        $sponsored = SponsorableEvents::alreadySponsored(array_map('intval', $events->modelKeys()));
        $grantId = $request->integer('grant') ?: null;
        $grant = $grantId === null ? null : $desk->selectableGrants($sponsor)->firstWhere('id', $grantId);
        $active = $desk->activeEventIds($grant?->getKey() === null ? null : (int) $grant->getKey());

        return response()->json([
            'data' => $events->map(fn ($event): array => [
                ...(array) SponsorshipPayload::event($event, $tz),
                'already_sponsored' => in_array((int) $event->getKey(), $sponsored, true),
                'active_on_grant' => in_array((int) $event->getKey(), $active, true),
            ])->values()->all(),
            'meta' => [
                'has_more' => $events->count() < SponsorshipDesk::EVENTS_MAX && $desk->eventsQuery($sponsor, $search)->count() > $events->count(),
                'total' => SponsorableEvents::of($sponsor)->count(),
            ],
        ]);
    }

    /** Il listino: per ogni posizione la descrizione, la rotazione, il prezzo di partenza e le durate. */
    public function catalog(SponsorshipDeskRequest $request, SponsorshipDesk $desk): JsonResponse
    {
        $online = $desk->paysOnline();

        $placements = array_map(function (SponsorshipPlacement $placement) use ($desk, $online): array {
            $durations = $desk->durations($placement);
            $prices = array_values(array_filter(array_column($durations, 'amount_cents'), fn ($cents): bool => $cents !== null));
            $from = $prices === [] ? null : min($prices);

            return [
                ...SponsorshipPayload::placement($placement),
                'description' => __('promotions.formats.'.$placement->value),
                'rotation' => trans_choice('promotions.formats.rotation', $placement->limit(), ['count' => $placement->limit()]),
                'sells_views' => $placement->sellsViews(),
                'from_cents' => $from,
                'from_label' => $from ? __('promotions.formats.from', ['price' => SponsorshipPayload::money((int) $from)]) : null,
                'priced' => $durations !== [] && $durations[0]['price_id'] !== null,
                'durations' => array_map(fn (array $d): array => [
                    'key' => $d['key'],
                    'months' => $d['months'],
                    'months_label' => SponsorshipPayload::months($d['months']),
                    'amount_cents' => $d['amount_cents'],
                    'amount_label' => SponsorshipPayload::money($d['amount_cents']),
                    'pays_online' => $online && $d['price_id'] !== null,
                ], $durations),
            ];
        }, SponsorshipPlacement::cases());

        return response()->json(['data' => [
            'pays_online' => $online,
            'price_vat' => __('promotions.page.price_vat'),
            'placements' => $placements,
        ]]);
    }

    /**
     * Invia la scelta (W-SP-03): con il pagamento online risponde
     * `mode: checkout` e `checkout_url`; senza, `mode: request`. 429 oltre i
     * cinque invii l'ora o con troppe richieste aperte, 422 se evento o durata
     * non vanno più bene, 409 se la pagina di pagamento non si apre.
     */
    public function store(SponsorshipDeskRequest $request, SponsorshipDesk $desk): JsonResponse
    {
        $sponsor = $request->sponsor();
        $actor = $request->actor();
        $placement = SponsorshipPlacement::from((string) $request->validated('placement'));
        $scope = (string) $request->validated('scope');
        $eventId = $request->validated('event_id') === null ? null : (int) $request->validated('event_id');
        $duration = (string) $request->validated('duration');
        $note = $request->validated('note');

        $result = $desk->submit($actor, $sponsor, $scope, $eventId, $placement, $duration, is_string($note) ? $note : null,
            $this->returnUrl($sponsor, false), $this->returnUrl($sponsor, true));

        if ($result->outcome === SponsorshipDeskOutcome::Incomplete) {
            $field = $desk->duration($placement, $duration) === null ? 'duration' : 'event_id';
            $message = $field === 'event_id' ? __('promotions.request.event_not_available') : $result->outcome->message();
            throw new ApiException(ApiErrorCode::ValidationFailed, $message, [$field => [$message]]);
        }

        $this->fail($result);

        return response()->json([
            'data' => $this->orderResult($result, $sponsor, true),
            'message' => $result->outcome === SponsorshipDeskOutcome::Requested ? __('promotions.request.sent').'. '.__('promotions.request.sent_body') : $result->outcome->message(),
        ], 201);
    }

    /** Riapre il pagamento di un ordine in attesa (W-SP-06). */
    public function retry(SponsorshipDeskRequest $request, SponsorshipDesk $desk): JsonResponse
    {
        $sponsor = $request->sponsor();
        $result = $desk->retry($sponsor, $request->routeId('order'), $this->returnUrl($sponsor, false), $this->returnUrl($sponsor, true));
        $this->fail($result);

        return response()->json(['data' => $this->orderResult($result, $sponsor, true), 'message' => $result->outcome->message()]);
    }

    /** Annulla un ordine in attesa di pagamento (W-SP-07); se intanto risulta pagato, lo dice. */
    public function cancel(SponsorshipDeskRequest $request, SponsorshipDesk $desk): JsonResponse
    {
        $sponsor = $request->sponsor();
        $result = $desk->cancel($sponsor, $request->routeId('order'));
        $this->fail($result);

        return response()->json(['data' => $this->orderResult($result, $sponsor, true), 'message' => $result->outcome->message()]);
    }

    /** Ritira una richiesta non ancora decisa (W-SP-08). */
    public function withdraw(SponsorshipDeskRequest $request, SponsorshipDesk $desk): JsonResponse
    {
        $sponsor = $request->sponsor();
        $result = $desk->withdraw($sponsor, $request->routeId('order'));

        return response()->json(['data' => $this->orderResult($result, $sponsor, true), 'message' => $result->outcome->message()]);
    }

    /**
     * Il ritorno dal checkout (W-SP-04): con `cancelled` la pagina di Stripe è
     * stata chiusa; altrimenti si chiede a Stripe se è pagato. Come la pagina
     * del pannello aperta con `?order=`, la può chiamare ogni membro.
     */
    public function reconcile(SponsorshipDeskRequest $request, SponsorshipDesk $desk): JsonResponse
    {
        $sponsor = $request->sponsor();
        $result = $desk->returnFromCheckout($sponsor, $request->routeId('order'), $request->boolean('cancelled'));
        abort_if($result === null, 404);

        return response()->json([
            'data' => $this->orderResult($result, $sponsor, $desk->canPurchase($request->actor(), $sponsor)),
            'message' => $result->outcome->message(),
        ]);
    }

    /**
     * Mette in evidenza subito un evento con un periodo attivo «a scelta»
     * (W-SP-09). Chi può modificare l'evento, collaboratore compreso.
     */
    public function activate(SponsorshipDeskRequest $request, SponsorshipDesk $desk): JsonResponse
    {
        $campaign = $desk->activate($request->actor(), $request->sponsor(), $request->routeId('grant'), (int) $request->validated('event_id'));

        if ($campaign === null) {
            $message = __('promotions.request.event_not_available');
            throw new ApiException(ApiErrorCode::ValidationFailed, $message, ['event_id' => [$message]]);
        }

        return response()->json([
            'data' => ['campaign_id' => (int) $campaign->getKey(), 'event_id' => (int) $campaign->event_id, 'grant_id' => (int) $campaign->sponsorship_grant_id],
            'message' => __('promotions.success'),
        ]);
    }

    /** Ferma una campagna di un proprio periodo «a scelta» (W-SP-10): chi può modificare l'evento. */
    public function stop(SponsorshipDeskRequest $request, SponsorshipDesk $desk): JsonResponse
    {
        $campaign = $desk->stop($request->actor(), $request->sponsor(), $request->routeId('campaign'));

        return response()->json([
            'data' => ['campaign_id' => (int) $campaign->getKey(), 'status' => $campaign->status->value],
            'message' => __('promotions.stopped'),
        ]);
    }

    /** Gli esiti che non sono riusciti diventano l'errore che l'app sa leggere. */
    private function fail(SponsorshipDeskResult $result): void
    {
        $message = $result->outcome->message();

        match ($result->outcome) {
            SponsorshipDeskOutcome::SlowDown, SponsorshipDeskOutcome::TooManyRequests => throw new ApiException(ApiErrorCode::RateLimited, $message, ['order' => [$message]]),
            SponsorshipDeskOutcome::CheckoutFailed, SponsorshipDeskOutcome::CancelFailed, SponsorshipDeskOutcome::NoLongerPayable => throw new ApiException(ApiErrorCode::Conflict, $message),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function orderResult(SponsorshipDeskResult $result, Venue|Organizer $sponsor, bool $canPurchase): array
    {
        $order = $result->order;

        return [
            'mode' => $result->outcome->value,
            'checkout_url' => $result->outcome === SponsorshipDeskOutcome::CheckoutOpened ? $order?->checkout_url : null,
            'order' => $order === null ? null : SponsorshipPayload::order($order->loadMissing('event.media'), SponsorshipDesk::timezone($sponsor), $canPurchase),
        ];
    }

    /**
     * L'indirizzo a cui Stripe riporta chi ha pagato dall'app: la pagina
     * `/app/sponsorizzazioni/ritorno`, App Link verso l'app. `{ORDER}` lo
     * sostituisce `SponsorshipOrders` quando l'ordine esiste.
     */
    private function returnUrl(Venue|Organizer $sponsor, bool $cancelled): string
    {
        $type = $sponsor instanceof Venue ? 'venue' : 'organizer';

        return route('app.sponsorship-return').'?subject='.$type.'&id='.$sponsor->getKey().'&order={ORDER}'.($cancelled ? '&cancelled=1' : '');
    }
}
