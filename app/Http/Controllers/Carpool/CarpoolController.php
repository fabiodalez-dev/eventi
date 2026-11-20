<?php

declare(strict_types=1);

namespace App\Http\Controllers\Carpool;

use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Http\Requests\Carpool\CarpoolActionRequest;
use App\Http\Requests\Carpool\CarpoolCaseRequest;
use App\Http\Requests\Carpool\CarpoolQueryRequest;
use App\Http\Requests\Carpool\RideChatRequest;
use App\Http\Requests\Carpool\RideSearchRequest;
use App\Http\Resources\Carpool\RideResource;
use App\Models\CarpoolCase;
use App\Models\EventOccurrence;
use App\Models\RideConversation;
use App\Models\RideOffer;
use App\Models\RideRequest;
use App\Models\RideSearch;
use App\Models\RideTemplate;
use App\Queries\CarpoolQuery;
use App\Services\Carpool\CarpoolAccess;
use App\Services\Carpool\CarpoolLifecycle;
use App\Services\Carpool\CarpoolService;
use App\Services\Carpool\CarpoolTerms;
use App\Services\Carpool\CommunityNotices;
use App\Services\Carpool\CommunitySafety;
use App\Services\Carpool\RideChat;
use App\Services\Carpool\RideDiscovery;
use App\Support\PublicName;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class CarpoolController extends Controller
{
    public function __construct(private CarpoolAccess $access) {}

    /** Relazioni che servono alla riga evento delle pagine: locandina, sede, città. */
    private const OCCURRENCE_RELATIONS = ['event.media', 'event.venue', 'event.city', 'venue'];

    /**
     * @param  array<string, mixed>  $data  la risposta, uguale per il sito e per l'app
     * @param  array<string, mixed>  $viewData  solo per la pagina web: modelli e misure che l'app non riceve
     */
    private function page(Request $request, string $view, string $title, array $data, array $viewData = []): View|JsonResponse
    {
        $data['access'] = $this->access->state($request->user());

        return $request->expectsJson() ? response()->json(['data' => $data])
            : view('carpool.'.$view, [...$data, ...$viewData, 'tabs' => self::tabs(), 'meta' => new PageMeta(__($title), __($title), indexable: false)]);
    }

    /**
     * Le tre schede di tutte le pagine dei passaggi: Passaggi, Messaggi, Aiuto
     * (requisiti, regole e assistenza). I contatori li aggiorna `carpool.js`.
     *
     * @return list<array{href: string, label: string, active: bool, count?: string}>
     */
    public static function tabs(): array
    {
        return [
            ['href' => route('carpool.index'), 'label' => __('carpool.web.tabs.rides'), 'active' => request()->routeIs('carpool.index', 'carpool.offer', 'carpool.request', 'carpool.dates', 'carpool.create', 'carpool.reviews'), 'count' => 'pending'],
            ['href' => route('carpool.chats'), 'label' => __('carpool.web.tabs.messages'), 'active' => request()->routeIs('carpool.chats', 'carpool.chat'), 'count' => 'conversations'],
            ['href' => route('carpool.requirements'), 'label' => __('carpool.web.tabs.help'), 'active' => request()->routeIs('carpool.requirements', 'carpool.terms', 'carpool.cases', 'carpool.case')],
        ];
    }

    /**
     * Le date con locandina e sede, indicizzate per id, per le righe evento.
     *
     * @param  iterable<EventOccurrence|null>  $occurrences
     * @return EloquentCollection<int, EventOccurrence>
     */
    private function occurrenceMap(iterable $occurrences): EloquentCollection
    {
        $map = EloquentCollection::make(collect($occurrences)->filter()->unique('id')->values()->all())->keyBy('id');
        $map->loadMissing(self::OCCURRENCE_RELATIONS);

        return $map;
    }

    /**
     * La finestra in cui si può partire per una tratta, nell'ora della città,
     * come la vuole un campo `datetime-local` (`min`/`max`) e come la legge una
     * persona. Le stesse regole di `CarpoolQuery::allowed()`.
     *
     * @return array<string, array{min: string, max: string, from: string, to: string}>
     */
    private function departureWindows(EventOccurrence $occurrence): array
    {
        $timezone = $occurrence->event->city->timezone;
        $start = CarbonImmutable::instance($occurrence->starts_at);
        $end = CarbonImmutable::instance($occurrence->effective_ends_at);
        $now = app(CarpoolQuery::class)->now($occurrence)->addMinute();
        $window = fn (CarbonImmutable $from, CarbonImmutable $to): array => [
            'min' => $from->max($now)->setTimezone($timezone)->format('Y-m-d\\TH:i'),
            'max' => $to->setTimezone($timezone)->format('Y-m-d\\TH:i'),
            'from' => $from->max($now)->setTimezone($timezone)->format('d/m H:i'),
            'to' => $to->setTimezone($timezone)->format('d/m H:i'),
        ];

        return ['outbound' => $window($start->subDay(), $end), 'return' => $window($start, $end->addDay())];
    }

    public function terms(Request $request): View|JsonResponse
    {
        return $this->page($request, 'terms', 'carpool.terms', ['terms' => app(CarpoolTerms::class)->text(), 'version' => config('carpool.terms_version')]);
    }

    public function requirements(Request $request): View|JsonResponse
    {
        return $this->page($request, 'requirements', 'carpool.requirements', ['terms' => app(CarpoolTerms::class)->text()]);
    }

    public function dates(CarpoolQueryRequest $request, EventOccurrence $occurrence): View|JsonResponse
    {
        abort_unless(app(CarpoolQuery::class)->visible($occurrence), 404);
        $eligible = $request->user() && $this->access->eligible($request->user());
        $offers = $eligible ? app(RideDiscovery::class)->offers($request->user(), $occurrence, $request->validated())->paginate(20)->withQueryString() : null;
        // Chi cerca vede gli altri: il proprio annuncio sta in «I miei passaggi», e qui gli proporrebbe le sue offerte.
        $wanted = $eligible ? RideSearch::where('occurrence_id', $occurrence->id)->where('active', true)->where('is_public', true)->where('latest_at', '>', now())
            ->where('user_id', '!=', $request->user()->id)
            ->whereNotIn('user_id', app(RideDiscovery::class)->blockedIds($request->user()))->whereHas('user', fn ($q) => app(RideDiscovery::class)->eligibleQuery($q))->with('user.communityProfile')->paginate(20, pageName: 'wanted_page') : null;

        return $this->page($request, 'dates', 'carpool.title', ['occurrence_id' => $occurrence->id, 'event_title' => $occurrence->event->title,
            'timezone' => $occurrence->event->city->timezone, 'starts_at' => $occurrence->starts_at->toIso8601String(), 'ends_at' => $occurrence->effective_ends_at->toIso8601String(),
            'event_date' => $occurrence->starts_at->setTimezone($occurrence->event->city->timezone)->format('d/m/Y H:i'),
            'offers' => $offers?->getCollection()->map(fn ($o) => RideResource::offer($o, $request->user()))->all() ?? [],
            'my_offers' => $eligible ? RideOffer::where('driver_id', $request->user()->id)->where('occurrence_id', $occurrence->id)->where('status', 'open')->where('departure_at', '>', now())->get()->map(fn ($o) => RideResource::offer($o, $request->user()))->all() : [],
            'wanted' => ($wanted?->getCollection() ?? collect())->map(fn ($s) => ['id' => $s->id, 'name' => PublicName::for($s->user, $request->user()),
                'zone' => $s->zone, 'seats' => $s->seats, 'leg_label' => $s->leg->label(), 'latest_at' => $s->latest_at->toIso8601String()])->all(),
            'wanted_has_more' => $wanted?->hasMorePages() ?? false, 'wanted_page' => $wanted?->currentPage() ?? 1,
            'has_more' => $offers?->hasMorePages() ?? false, 'page' => $offers?->currentPage() ?? 1],
            ['occurrence' => $this->occurrenceMap([$occurrence])->first(), 'windows' => $this->departureWindows($occurrence)]);
    }

    public function create(Request $request, EventOccurrence $occurrence): View|JsonResponse
    {
        abort_unless(app(CarpoolQuery::class)->visible($occurrence), 404);
        $templates = $request->user() ? RideTemplate::where('user_id', $request->user()->id)->orderBy('name')->get(['id', 'name', 'settings'])->toArray() : [];

        return $this->page($request, 'create', 'carpool.offer', ['occurrence_id' => $occurrence->id, 'event_title' => $occurrence->event->title,
            'timezone' => $occurrence->event->city->timezone, 'starts_at' => $occurrence->starts_at->toIso8601String(), 'ends_at' => $occurrence->effective_ends_at->toIso8601String(),
            'event_date' => $occurrence->starts_at->setTimezone($occurrence->event->city->timezone)->format('d/m/Y H:i'), 'templates' => $templates],
            ['occurrence' => $this->occurrenceMap([$occurrence])->first(), 'windows' => $this->departureWindows($occurrence)]);
    }

    public function index(CarpoolQueryRequest $request): View|JsonResponse
    {
        $user = $request->user();
        $tabs = ['offered', 'requested', 'searches', 'history'];
        $tab = in_array($request->input('tab'), $tabs, true) ? (string) $request->input('tab') : null;
        // Sul sito, senza una scheda scelta, si apre la prima che ha qualcosa da mostrare: chi ha
        // solo una richiesta accettata non deve trovare «Offro» vuoto e cercare altrove.
        $counts = $request->expectsJson() ? [] : [
            'offered' => RideOffer::where('driver_id', $user->id)->whereNotIn('status', ['cancelled', 'completed'])->count(),
            'requested' => RideRequest::where('user_id', $user->id)->whereIn('status', ['pending', 'accepted'])->whereHas('offer', fn ($offer) => $offer->whereNotIn('status', ['cancelled', 'completed']))->count(),
            'searches' => RideSearch::where('user_id', $user->id)->count(),
        ];
        $selected = $tab ?? (collect($counts)->filter()->keys()->first() ?? 'offered');
        if (! $request->expectsJson() && $tab === null) {
            $request->merge(['tab' => $selected]);
        }
        $history = $request->input('tab') === 'history';
        $all = $request->expectsJson() && ! $request->filled('tab');
        $offers = RideOffer::where('driver_id', $user->id)->when(! $all, fn ($q) => $history ? $q->whereIn('status', ['cancelled', 'completed']) : $q->whereNotIn('status', ['cancelled', 'completed']))->with(['driver.communityProfile', 'driver.carpoolProfile', 'driver.roles', 'occurrence.event.city', 'occurrence.event.media'])
            ->withSum(['requests as occupied_seats' => fn ($q) => $q->where('status', 'accepted')], 'seats')->orderByDesc('id')->paginate(20, pageName: 'page');
        $requests = RideRequest::where('user_id', $user->id)->when(! $all, fn ($q) => $history
            ? $q->where(fn ($past) => $past->whereNotIn('status', ['pending', 'accepted'])->orWhereHas('offer', fn ($offer) => $offer->whereIn('status', ['cancelled', 'completed'])))
            : $q->whereIn('status', ['pending', 'accepted'])->whereHas('offer', fn ($offer) => $offer->whereNotIn('status', ['cancelled', 'completed'])))->with(['offer' => fn ($offer) => $offer->withSum(['requests as occupied_seats' => fn ($q) => $q->where('status', 'accepted')], 'seats'),
                'offer.occurrence.event.city', 'offer.occurrence.event.media', 'offer.driver.communityProfile', 'offer.driver.roles', 'offer.driver.carpoolProfile', 'user.communityProfile', 'review', 'conversation'])->orderByDesc('id')->paginate(20, pageName: 'requests_page');
        $searches = RideSearch::where('user_id', $user->id)->with('occurrence.event')->orderByDesc('id')->paginate(20, pageName: 'searches_page');

        return $this->page($request, 'index', 'carpool.mine', ['offers' => $offers->map(fn ($o) => RideResource::offer($o, $user))->all(),
            'requests' => $requests->map(fn ($r) => [...RideResource::request($r, $user), 'offer' => RideResource::offer($r->offer, $user)])->all(),
            'searches' => $searches->map(fn ($s) => [...$s->only(['id', 'zone', 'seats', 'active', 'is_public', 'alerts_enabled', 'occurrence_id']),
                'event_title' => $s->occurrence?->event?->title, 'leg_label' => $s->leg->label()])->all(),
            'templates' => RideTemplate::where('user_id', $user->id)->get(['id', 'name', 'settings'])->toArray(),
            'requests_has_more' => $requests->hasMorePages(), 'requests_page' => $requests->currentPage(), 'searches_has_more' => $searches->hasMorePages(), 'searches_page' => $searches->currentPage(),
            'summary' => app(CommunityNotices::class)->summary($user), 'has_more' => $offers->hasMorePages(), 'page' => $offers->currentPage()],
            ['selected_tab' => $selected, 'counts' => $counts, 'occurrences' => $this->occurrenceMap([
                ...$offers->getCollection()->pluck('occurrence'), ...$requests->getCollection()->pluck('offer.occurrence'), ...$searches->getCollection()->pluck('occurrence'),
            ])]);
    }

    public function offer(CarpoolQueryRequest $request, RideOffer $offer): View|JsonResponse
    {
        Gate::authorize('view', $offer);
        $user = $request->user();
        $offer->loadMissing(['driver.communityProfile', 'driver.roles', 'occurrence.event.city', 'occurrence.event.media']);
        $requests = $offer->requests()->when($offer->driver_id !== $user->id, fn ($q) => $q->where('user_id', $user->id))->with(['user.communityProfile', 'user.roles', 'conversation', 'review'])->orderByDesc('id')->paginate(20);

        return $this->page($request, 'offer', 'carpool.title', ['offer' => RideResource::offer($offer, $user),
            'requests' => $requests->map(fn ($r) => RideResource::request($r, $user))->all(), 'has_more' => $requests->hasMorePages(), 'page' => $requests->currentPage()],
            ['occurrence' => $this->occurrenceMap([$offer->occurrence])->first(), 'windows' => $offer->occurrence !== null ? $this->departureWindows($offer->occurrence) : [],
                // Chi ha ritirato una richiesta, o se l'è vista annullare da un cambio delle condizioni,
                // può chiedere di nuovo: la pagina gli rimette il modulo invece della richiesta chiusa.
                'can_request_again' => $offer->driver_id !== $user->id && app(CarpoolService::class)->repeatRefusal($user, $offer) === null]);
    }

    public function rideRequest(Request $request, RideRequest $rideRequest): View|JsonResponse
    {
        Gate::authorize('view', $rideRequest);
        $rideRequest->loadMissing(['user.communityProfile', 'user.roles', 'offer.driver.communityProfile', 'offer.driver.roles', 'offer.occurrence.event.city', 'review', 'conversation']);

        return $this->page($request, 'request', 'carpool.title', ['ride' => RideResource::request($rideRequest, $request->user()), 'offer' => RideResource::offer($rideRequest->offer, $request->user())],
            ['occurrence' => $this->occurrenceMap([$rideRequest->offer->occurrence])->first()]);
    }

    public function action(CarpoolActionRequest $request, string $action): JsonResponse|RedirectResponse
    {
        try {
            $result = app(CarpoolService::class)->execute($request->user(), $action, $request->validated());
        } catch (HttpExceptionInterface $refusal) {
            return $this->refused($request, $refusal, $action) ?? throw $refusal;
        }
        if ($action === 'revoke-adult') {
            app(CarpoolLifecycle::class)->reconcileUser($request->user()->id);
        }

        return $this->mutation($request, $result);
    }

    public function discovery(RideSearchRequest $request, string $action): JsonResponse|RedirectResponse
    {
        try {
            $result = app(RideDiscovery::class)->execute($request->user(), $action, $request->validated());
        } catch (HttpExceptionInterface $refusal) {
            return $this->refused($request, $refusal, $action) ?? throw $refusal;
        }

        return $this->mutation($request, $result);
    }

    /**
     * Un'azione rifiutata dal servizio (409 o 422) inviata da un modulo del sito: si torna al
     * modulo con i dati scritti e il messaggio accanto al campo che l'ha causato — la partenza
     * fuori dalla finestra sotto «Data e ora di partenza», i posti sotto i posti. Le regole
     * restano nel servizio: qui cambia solo dove si legge il rifiuto. L'app (JSON) riceve la
     * stessa risposta di prima; tutto il resto lo gestisce il gestore comune in bootstrap/app.php.
     */
    private function refused(Request $request, HttpExceptionInterface $refusal, string $action): ?RedirectResponse
    {
        if ($request->expectsJson() || ! in_array($refusal->getStatusCode(), [409, 422], true) || ! $request->hasSession()) {
            return null;
        }
        $message = trim($refusal->getMessage()) !== '' ? $refusal->getMessage()
            : __($refusal->getStatusCode() === 409 ? 'errors.form.conflict' : 'errors.form.unprocessable');
        $field = match (true) {
            $message === __('carpool.errors.departure') => $action === 'search' ? 'earliest_at' : 'departure_at',
            in_array($message, [__('carpool.errors.capacity'), __('carpool.errors.reduction')], true) => $action === 'update' ? 'capacity' : 'seats',
            $message === __('carpool.errors.stop') => 'stop_index',
            default => 'form',
        };
        $previous = (string) $request->headers->get('referer', '');
        $host = $previous !== '' ? parse_url($previous, PHP_URL_HOST) : null;
        $target = is_string($host) && strcasecmp($host, $request->getHost()) === 0 ? $previous : match (true) {
            $action === 'offer' && $request->filled('occurrence_id') => route('carpool.create', $request->integer('occurrence_id')),
            $request->filled('offer_id') => route('carpool.offer', $request->integer('offer_id')),
            $request->filled('request_id') => route('carpool.request', $request->integer('request_id')),
            default => route('carpool.index'),
        };

        return redirect()->to($target)->withInput($request->except(['_token', 'request_key']))->withErrors([$field => $message]);
    }

    /** @param array{entity: string, id: int} $result */
    private function mutation(Request $request, array $result): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['data' => $result, 'summary' => app(CommunityNotices::class)->summary($request->user())]);
        }
        $target = match ($result['entity']) {
            'offer' => route('carpool.offer', $result['id']), 'request' => route('carpool.request', $result['id']), 'profile' => route('carpool.requirements'), 'case' => route('carpool.case', $result['id']), default => route('carpool.index')
        };

        if ($request->route('action') === 'declare' && $request->filled('return_to')) {
            $target = $request->string('return_to')->toString();
        }
        // Chi decide una richiesta resta sull'offerta: le altre richieste da gestire sono lì.
        if (in_array($request->route('action'), ['accept', 'decline'], true) && ($offerId = RideRequest::whereKey($result['id'])->value('ride_offer_id')) !== null) {
            $target = route('carpool.offer', $offerId);
        }

        return redirect($target)->with('status', __('carpool.saved'));
    }

    public function summary(Request $request): JsonResponse
    {
        return response()->json(['data' => app(CommunityNotices::class)->summary($request->user())]);
    }

    public function chats(CarpoolQueryRequest $request): View|JsonResponse
    {
        $user = $request->user();
        $this->access->notImpersonating();
        $chats = RideConversation::whereIn('ride_request_id', RideRequest::where('user_id', $user->id)->orWhereIn('ride_offer_id', RideOffer::where('driver_id', $user->id)->select('id'))->select('id'))
            ->whereIn('id', DB::table('ride_chat_preferences')->where('user_id', $user->id)->where('archived', $request->boolean('archived'))->select('ride_conversation_id'))
            ->with(['rideRequest.offer' => fn ($offer) => $offer->withSum(['requests as occupied_seats' => fn ($q) => $q->where('status', 'accepted')], 'seats'),
                'rideRequest.offer.occurrence.event.city', 'rideRequest.offer.occurrence.event.media', 'rideRequest.offer.driver.communityProfile', 'rideRequest.offer.driver.roles', 'rideRequest.user.communityProfile', 'rideRequest.user.roles', 'rideRequest.review', 'rideRequest.conversation'])->orderByDesc('updated_at')->orderByDesc('id')->paginate(20);

        return $this->page($request, 'chats', 'carpool.messages', ['chats' => $chats->map(fn ($chat) => [
            'id' => $chat->id, 'offer' => RideResource::offer($chat->rideRequest->offer, $user),
            'ride' => RideResource::request($chat->rideRequest, $user), 'readable' => $this->access->canReadChat($user, $chat),
            'unread' => DB::table('chat_message_notifications')->where('conversation_id', $chat->conversation_id)->where('messageable_id', $user->id)->where('is_sender', false)->where('is_seen', false)->count(),
            'archived' => (bool) DB::table('ride_chat_preferences')->where('ride_conversation_id', $chat->id)->where('user_id', $user->id)->value('archived'),
        ])->all(), 'has_more' => $chats->hasMorePages(), 'page' => $chats->currentPage(), 'archived' => $request->boolean('archived')],
            ['occurrences' => $this->occurrenceMap($chats->getCollection()->pluck('rideRequest.offer.occurrence'))]);
    }

    public function chat(CarpoolQueryRequest $request, RideConversation $chat): View|JsonResponse
    {
        Gate::authorize('view', $chat);
        $user = $request->user();
        $chat->loadMissing(['rideRequest.user.communityProfile', 'rideRequest.user.roles', 'rideRequest.user.carpoolProfile', 'rideRequest.review', 'rideRequest.conversation',
            'rideRequest.offer.driver.communityProfile', 'rideRequest.offer.driver.roles', 'rideRequest.offer.driver.carpoolProfile', 'rideRequest.offer.occurrence.event.city']);

        return $this->page($request, 'chat', 'carpool.messages', ['chat_id' => $chat->id, 'offer' => RideResource::offer($chat->rideRequest->offer, $user),
            'ride' => RideResource::request($chat->rideRequest, $user),
            'messages' => app(RideChat::class)->messages($user, $chat, $request->integer('after'), $request->filled('before') ? $request->integer('before') : null),
            'writable' => app(RideChat::class)->writable($user, $chat),
            'archived' => (bool) DB::table('ride_chat_preferences')->where('ride_conversation_id', $chat->id)->where('user_id', $user->id)->value('archived'),
            'muted' => (bool) DB::table('ride_chat_preferences')->where('ride_conversation_id', $chat->id)->where('user_id', $user->id)->value('muted')],
            ['occurrence' => $this->occurrenceMap([$chat->rideRequest->offer->occurrence])->first()]);
    }

    public function chatAction(RideChatRequest $request, RideConversation $chat, string $action): JsonResponse|RedirectResponse
    {
        Gate::authorize('view', $chat);
        $data = $request->validated();
        if ($action === 'send') {
            $id = app(RideChat::class)->send($request->user(), $chat, $data['body'], $data['request_key']);
        } else {
            app(RideChat::class)->preferences($request->user(), $chat, $data);
            $id = 0;
        }

        return $request->expectsJson() ? response()->json(['data' => ['id' => $id], 'summary' => app(CommunityNotices::class)->summary($request->user())]) : back()->with('status', __('carpool.saved'));
    }

    public function cases(CarpoolQueryRequest $request): View|JsonResponse
    {
        $user = $request->user();
        $cases = CarpoolCase::where('reporter_id', $user->id)->orWhereHas('messages', fn ($q) => $q->where('recipient_id', $user->id))->orderByDesc('id')->paginate(20);

        return $this->page($request, 'cases', 'carpool.cases', ['cases' => $cases->map(fn ($case) => $case->only(['id', 'reason', 'status', 'created_at']))->all(), 'has_more' => $cases->hasMorePages(), 'page' => $cases->currentPage()]);
    }

    public function case(CarpoolQueryRequest $request, CarpoolCase $case): View|JsonResponse
    {
        Gate::authorize('view', $case);

        return $this->page($request, 'case', 'carpool.support', ['case_id' => $case->id, 'case_status' => $case->status->label(),
            'body' => $case->reporter_id === $request->user()->id ? $case->body : null,
            'messages' => app(CommunitySafety::class)->correspondence($request->user(), $case, $request->filled('before') ? $request->integer('before') : null)]);
    }

    public function report(CarpoolCaseRequest $request): JsonResponse|RedirectResponse
    {
        $case = app(CommunitySafety::class)->report($request->user(), $request->validated());

        return $this->mutation($request, ['entity' => 'case', 'id' => $case->id]);
    }

    public function reply(CarpoolCaseRequest $request, CarpoolCase $case): JsonResponse|RedirectResponse
    {
        Gate::authorize('view', $case);
        app(CommunitySafety::class)->reply($request->user(), $case, $request->validated('body'), $request->validated('request_key'));

        return $this->mutation($request, ['entity' => 'case', 'id' => $case->id]);
    }
}
