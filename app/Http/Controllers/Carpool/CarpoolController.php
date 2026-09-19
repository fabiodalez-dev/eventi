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
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class CarpoolController extends Controller
{
    public function __construct(private CarpoolAccess $access) {}

    /** @param array<string, mixed> $data */
    private function page(Request $request, string $view, string $title, array $data): View|JsonResponse
    {
        $data['access'] = $this->access->state($request->user());

        return $request->expectsJson() ? response()->json(['data' => $data])
            : view('carpool.'.$view, [...$data, 'meta' => new PageMeta(__($title), __($title), indexable: false)]);
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
        $wanted = $eligible ? RideSearch::where('occurrence_id', $occurrence->id)->where('active', true)->where('is_public', true)->where('latest_at', '>', now())
            ->whereNotIn('user_id', app(RideDiscovery::class)->blockedIds($request->user()))->whereHas('user', fn ($q) => app(RideDiscovery::class)->eligibleQuery($q))->with('user.communityProfile')->paginate(20, pageName: 'wanted_page') : null;

        return $this->page($request, 'dates', 'carpool.title', ['occurrence_id' => $occurrence->id, 'event_title' => $occurrence->event->title,
            'timezone' => $occurrence->event->city->timezone, 'starts_at' => $occurrence->starts_at->toIso8601String(), 'ends_at' => $occurrence->effective_ends_at->toIso8601String(),
            'event_date' => $occurrence->starts_at->setTimezone($occurrence->event->city->timezone)->format('d/m/Y H:i'),
            'offers' => $offers?->getCollection()->map(fn ($o) => RideResource::offer($o, $request->user()))->all() ?? [],
            'my_offers' => $eligible ? RideOffer::where('driver_id', $request->user()->id)->where('occurrence_id', $occurrence->id)->where('status', 'open')->where('departure_at', '>', now())->get()->map(fn ($o) => RideResource::offer($o, $request->user()))->all() : [],
            'wanted' => ($wanted?->getCollection() ?? collect())->map(fn ($s) => ['id' => $s->id, 'name' => $s->user->communityProfile->display_name ?? $s->user->name,
                'zone' => $s->zone, 'seats' => $s->seats, 'leg_label' => $s->leg->label(), 'latest_at' => $s->latest_at->toIso8601String()])->all(),
            'wanted_has_more' => $wanted?->hasMorePages() ?? false, 'wanted_page' => $wanted?->currentPage() ?? 1,
            'has_more' => $offers?->hasMorePages() ?? false, 'page' => $offers?->currentPage() ?? 1]);
    }

    public function create(Request $request, EventOccurrence $occurrence): View|JsonResponse
    {
        abort_unless(app(CarpoolQuery::class)->visible($occurrence), 404);
        $templates = $request->user() ? RideTemplate::where('user_id', $request->user()->id)->orderBy('name')->get(['id', 'name', 'settings'])->toArray() : [];

        return $this->page($request, 'create', 'carpool.offer', ['occurrence_id' => $occurrence->id, 'event_title' => $occurrence->event->title,
            'timezone' => $occurrence->event->city->timezone, 'starts_at' => $occurrence->starts_at->toIso8601String(), 'ends_at' => $occurrence->effective_ends_at->toIso8601String(),
            'event_date' => $occurrence->starts_at->setTimezone($occurrence->event->city->timezone)->format('d/m/Y H:i'), 'templates' => $templates]);
    }

    public function index(CarpoolQueryRequest $request): View|JsonResponse
    {
        $user = $request->user();
        $history = $request->input('tab') === 'history';
        $all = $request->expectsJson() && ! $request->filled('tab');
        $offers = RideOffer::where('driver_id', $user->id)->when(! $all, fn ($q) => $history ? $q->whereIn('status', ['cancelled', 'completed']) : $q->whereNotIn('status', ['cancelled', 'completed']))->with(['driver.communityProfile', 'driver.carpoolProfile', 'occurrence.event.city'])->orderByDesc('id')->paginate(20, pageName: 'page');
        $requests = RideRequest::where('user_id', $user->id)->when(! $all, fn ($q) => $history
            ? $q->where(fn ($past) => $past->whereNotIn('status', ['pending', 'accepted'])->orWhereHas('offer', fn ($offer) => $offer->whereIn('status', ['cancelled', 'completed'])))
            : $q->whereIn('status', ['pending', 'accepted'])->whereHas('offer', fn ($offer) => $offer->whereNotIn('status', ['cancelled', 'completed'])))->with(['offer.occurrence.event.city', 'offer.driver.communityProfile', 'offer.driver.carpoolProfile', 'user.communityProfile', 'review', 'conversation'])->orderByDesc('id')->paginate(20, pageName: 'requests_page');
        $searches = RideSearch::where('user_id', $user->id)->with('occurrence.event')->orderByDesc('id')->paginate(20, pageName: 'searches_page');

        return $this->page($request, 'index', 'carpool.mine', ['offers' => $offers->map(fn ($o) => RideResource::offer($o, $user))->all(),
            'requests' => $requests->map(fn ($r) => [...RideResource::request($r, $user), 'offer' => RideResource::offer($r->offer, $user)])->all(),
            'searches' => $searches->map(fn ($s) => [...$s->only(['id', 'zone', 'seats', 'active', 'is_public', 'alerts_enabled', 'occurrence_id']),
                'event_title' => $s->occurrence?->event?->title, 'leg_label' => $s->leg->label()])->all(),
            'templates' => RideTemplate::where('user_id', $user->id)->get(['id', 'name', 'settings'])->toArray(),
            'requests_has_more' => $requests->hasMorePages(), 'requests_page' => $requests->currentPage(), 'searches_has_more' => $searches->hasMorePages(), 'searches_page' => $searches->currentPage(),
            'summary' => app(CommunityNotices::class)->summary($user), 'has_more' => $offers->hasMorePages(), 'page' => $offers->currentPage()]);
    }

    public function offer(CarpoolQueryRequest $request, RideOffer $offer): View|JsonResponse
    {
        Gate::authorize('view', $offer);
        $user = $request->user();
        $requests = $offer->requests()->when($offer->driver_id !== $user->id, fn ($q) => $q->where('user_id', $user->id))->with(['user.communityProfile', 'conversation', 'review'])->orderByDesc('id')->paginate(20);

        return $this->page($request, 'offer', 'carpool.title', ['offer' => RideResource::offer($offer, $user),
            'requests' => $requests->map(fn ($r) => RideResource::request($r, $user))->all(), 'has_more' => $requests->hasMorePages(), 'page' => $requests->currentPage()]);
    }

    public function rideRequest(Request $request, RideRequest $rideRequest): View|JsonResponse
    {
        Gate::authorize('view', $rideRequest);

        return $this->page($request, 'request', 'carpool.title', ['ride' => RideResource::request($rideRequest, $request->user()), 'offer' => RideResource::offer($rideRequest->offer, $request->user())]);
    }

    public function action(CarpoolActionRequest $request, string $action): JsonResponse|RedirectResponse
    {
        $result = app(CarpoolService::class)->execute($request->user(), $action, $request->validated());
        if ($action === 'revoke-adult') {
            app(CarpoolLifecycle::class)->reconcileUser($request->user()->id);
        }

        return $this->mutation($request, $result);
    }

    public function discovery(RideSearchRequest $request, string $action): JsonResponse|RedirectResponse
    {
        return $this->mutation($request, app(RideDiscovery::class)->execute($request->user(), $action, $request->validated()));
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
            ->with(['rideRequest.offer.occurrence.event.city', 'rideRequest.user.communityProfile', 'rideRequest.review', 'rideRequest.conversation'])->orderByDesc('updated_at')->orderByDesc('id')->paginate(20);

        return $this->page($request, 'chats', 'carpool.messages', ['chats' => $chats->map(fn ($chat) => [
            'id' => $chat->id, 'offer' => RideResource::offer($chat->rideRequest->offer, $user),
            'ride' => RideResource::request($chat->rideRequest, $user), 'readable' => $this->access->canReadChat($user, $chat),
            'unread' => DB::table('chat_message_notifications')->where('conversation_id', $chat->conversation_id)->where('messageable_id', $user->id)->where('is_sender', false)->where('is_seen', false)->count(),
            'archived' => (bool) DB::table('ride_chat_preferences')->where('ride_conversation_id', $chat->id)->where('user_id', $user->id)->value('archived'),
        ])->all(), 'has_more' => $chats->hasMorePages(), 'page' => $chats->currentPage(), 'archived' => $request->boolean('archived')]);
    }

    public function chat(CarpoolQueryRequest $request, RideConversation $chat): View|JsonResponse
    {
        Gate::authorize('view', $chat);
        $user = $request->user();

        return $this->page($request, 'chat', 'carpool.messages', ['chat_id' => $chat->id, 'offer' => RideResource::offer($chat->rideRequest->offer, $user),
            'ride' => RideResource::request($chat->rideRequest, $user),
            'messages' => app(RideChat::class)->messages($user, $chat, $request->integer('after'), $request->filled('before') ? $request->integer('before') : null),
            'writable' => app(RideChat::class)->writable($user, $chat),
            'archived' => (bool) DB::table('ride_chat_preferences')->where('ride_conversation_id', $chat->id)->where('user_id', $user->id)->value('archived'),
            'muted' => (bool) DB::table('ride_chat_preferences')->where('ride_conversation_id', $chat->id)->where('user_id', $user->id)->value('muted')]);
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
