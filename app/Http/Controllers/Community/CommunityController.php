<?php

declare(strict_types=1);

namespace App\Http\Controllers\Community;

use App\DTOs\PageMeta;
use App\Enums\PostIntent;
use App\Enums\ProfileVisibility;
use App\Enums\VenueStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Community\AttendanceRequest;
use App\Http\Requests\Community\CommentRequest;
use App\Http\Requests\Community\CommunityQueryRequest;
use App\Http\Requests\Community\CommunityReportRequest;
use App\Http\Requests\Community\ProfileRequest;
use App\Http\Requests\Community\PublicationRequest;
use App\Http\Requests\Web\Account\AuthEntryRequest;
use App\Http\Resources\V1\CommunityResource;
use App\Http\Resources\V1\OccurrenceResource;
use App\Http\Resources\V1\VenueResource;
use App\Models\City;
use App\Models\CommunityComment;
use App\Models\CommunityPost;
use App\Models\CommunityProfile;
use App\Models\EventOccurrence;
use App\Models\SavedEvent;
use App\Models\User;
use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;
use App\Services\Community\Community;
use App\Services\Community\CommunityAccess;
use App\Support\Api\ApiContext;
use App\Support\Api\ApiResponse;
use App\Support\CurrentCity;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class CommunityController extends Controller
{
    public function __construct(private readonly CommunityAccess $access, private readonly Community $community) {}

    private function viewer(Request $request): ?User
    {
        $user = $request->is('api/*') ? Auth::guard('sanctum')->user() : $request->user();

        return $user instanceof User ? $user : null;
    }

    private function city(): City
    {
        return app(CurrentCity::class)->get() ?? abort(404);
    }

    public function avatar(Request $request, CommunityProfile $profile): BinaryFileResponse
    {
        $viewer = $this->viewer($request);
        abort_unless($viewer?->id === $profile->user_id || $this->access->profiles($viewer)->whereKey($profile->id)->exists(), 404);
        $media = $profile->getFirstMedia('avatar');
        abort_unless($media !== null, 404);

        return response()->file($media->getPath(), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function feed(CommunityQueryRequest $request): View|JsonResponse
    {
        // La rotta è protetta da auth sia sul sito sia sull'API: il 401 non scatta mai, rende solo onesto il tipo.
        $user = $this->viewer($request) ?? abort(401);
        $scope = $request->string('scope', 'following')->toString();
        $posts = $this->community->feed($user, $this->city(), $scope, $request->string('sort')->toString(), $request->boolean('past'))->withQueryString();
        $context = ApiContext::forOccurrences($this->city(), [], $user, $posts->pluck('occurrence_id')->all());
        if ($request->expectsJson()) {
            $followingIds = $this->access->followingIds($user, $posts->pluck('user_id'));

            return ApiResponse::collection($posts->getCollection()->map(fn ($post) => CommunityResource::post($post, $user, $context, $followingIds))->all(), ['has_more' => $posts->hasMorePages(), 'next_page' => $posts->hasMorePages() ? $posts->currentPage() + 1 : null]);
        }

        return view('community.feed', ['posts' => $posts, 'scope' => $scope, 'meta' => new PageMeta(__('community.feed'), __('community.feed'), indexable: false)]);
    }

    public function people(CommunityQueryRequest $request): View|JsonResponse
    {
        $user = $this->viewer($request);
        $profiles = $this->community->people($user, $request->filled('q') ? $request->string('q')->toString() : null, $request->boolean('featured'))->withQueryString();
        $followingIds = $this->access->followingIds($user, $profiles->pluck('user_id'));
        if ($request->expectsJson()) {
            return ApiResponse::collection($profiles->getCollection()->map(fn ($p) => CommunityResource::profile($p, $user, $followingIds))->all(), ['has_more' => $profiles->hasMorePages(), 'next_page' => $profiles->hasMorePages() ? $profiles->currentPage() + 1 : null]);
        }

        return view('community.people', ['profiles' => $profiles, 'followingIds' => $followingIds, 'meta' => new PageMeta(__('community.people'), __('community.people'), indexable: false)]);
    }

    public function profile(CommunityQueryRequest $request, string $handle): View|JsonResponse|RedirectResponse
    {
        $user = $this->viewer($request);
        $profile = $this->access->profiles($user)->where('handle', $handle)->first();
        if ($profile === null && $user?->canParticipateInCommunity()) {
            $profile = $user->communityProfile()->where('handle', $handle)->first();
        }
        if ($profile === null) {
            $aliasId = DB::table('community_handle_aliases')->where('handle', $handle)->value('community_profile_id');
            $alias = $aliasId ? $this->access->profiles($user)->whereKey($aliasId)->first() : null;
            if ($alias === null && $user?->canParticipateInCommunity()) {
                $alias = $user->communityProfile()->whereKey($aliasId)->first();
            }
            if ($alias !== null) {
                if (! $request->expectsJson()) {
                    return redirect()->route('community.profile', ['handle' => $alias->handle], 302);
                }
                $profile = $alias;
            }
        }
        abort_unless($profile !== null, 404);
        $city = $profile->city ?? $this->city();
        // L'archivio del profilo è fatto solo di date concluse, la vista normale solo di quelle a venire.
        $posts = $this->access->posts($user, $city, ! $request->boolean('past'))->where('user_id', $profile->user_id)->orderByDesc('published_at')->orderByDesc('id')->paginate(20)->withQueryString();
        $participations = $this->access->participations($profile->user, $user, $city, $request->boolean('past'));
        $venues = $profile->venues()->where('status', VenueStatus::Approved)->with(['city', 'media'])->get();
        $followingIds = $this->access->followingIds($user, [$profile->user_id]);
        if ($request->expectsJson()) {
            $context = ApiContext::forOccurrences($city, [], $user, $posts->pluck('occurrence_id')->merge($participations->pluck('id'))->unique()->values()->all());

            return ApiResponse::item(['profile' => CommunityResource::profile($profile, $user, $followingIds), 'posts' => $posts->getCollection()->map(fn ($p) => CommunityResource::post($p, $user, $context, $followingIds))->all(),
                'participations_has_more' => $participations->hasMorePages(), 'participations' => $participations->getCollection()->map(fn ($date) => OccurrenceResource::toArray($date, $context))->all(),
                'venues' => $venues->map(fn ($v) => VenueResource::summary($v))->all(), 'has_more' => $posts->hasMorePages(), 'next_page' => $posts->hasMorePages() ? $posts->currentPage() + 1 : null]);
        }

        return view('community.profile', ['profile' => $profile, 'summary' => CommunityResource::profile($profile, $user, $followingIds), 'posts' => $posts, 'venues' => $venues, 'participations' => $participations->withQueryString(),
            'meta' => new PageMeta($profile->display_name, $profile->display_name, $profile->bio, route('community.profile', $profile->handle), indexable: $profile->indexable && $profile->visibility === ProfileVisibility::Public)]);
    }

    public function post(Request $request, CommunityPost $post): View|JsonResponse
    {
        $user = $this->viewer($request);
        abort_unless($this->access->canViewPost($user, $post), 404);
        $post = $this->access->posts($user, $post->occurrence->event->city, null)->findOrFail($post->id);
        $comments = $this->access->comments($user, $post)->orderBy('created_at')->orderBy('id')->paginate(30)->withQueryString();
        // Una query per la pagina, non una per commento: visibilità degli autori e post di appartenenza.
        $visibleProfileIds = $this->access->visibleProfileIds($user, $comments->getCollection()->pluck('user_id'));
        $comments->getCollection()->each(fn (CommunityComment $comment) => $comment->setRelation('post', $post));
        if ($request->expectsJson()) {
            $context = ApiContext::forOccurrences($post->occurrence->event->city, [], $user, [$post->occurrence_id]);

            return ApiResponse::item(['post' => CommunityResource::post($post, $user, $context, $this->access->followingIds($user, [$post->user_id])),
                'comments' => $comments->getCollection()->map(fn ($c) => CommunityResource::comment($c, $user, $visibleProfileIds))->all(), 'has_more' => $comments->hasMorePages(), 'next_page' => $comments->hasMorePages() ? $comments->currentPage() + 1 : null]);
        }

        // Stessa regola di can_comment nel JSON: senza profilo il modulo non serve, serve completarlo.
        $canComment = $this->access->state($user)['can_comment'];

        return view('community.post', ['post' => $post, 'comments' => $comments, 'visibleProfileIds' => $visibleProfileIds, 'canComment' => $canComment, 'meta' => new PageMeta(__('community.post'), __('community.post'), indexable: false)]);
    }

    public function settings(AuthEntryRequest $request): View|JsonResponse
    {
        $user = $request->user();
        if ($request->hasSession()) {
            $request->rememberDestination();
        }
        $profile = $user->communityProfile;
        $venues = Venue::query()->where('status', VenueStatus::Approved)->whereIn('id', $user->follows()->where('followable_type', 'venue')->select('followable_id'))->orderBy('name')->get();
        /*
         * Il nome di battesimo come nome pubblico proposto, e solo quando il
         * profilo non esiste ancora.
         *
         * Chi si iscrive ha già lasciato nome e cognome, e ritrovarsi un modulo
         * vuoto sembra un doppio lavoro. Il cognome però resta fuori, e il nome
         * utente resta da scegliere: il profilo è un'identità **pubblica** —
         * compare in un indirizzo e nell'elenco di chi va a un evento — mentre
         * i dati dell'iscrizione non lo sono. Proporre `mario_rossi` spingerebbe
         * a pubblicare il cognome senza che nessuno l'abbia deciso.
         */
        $proposta = $profile === null ? ['display_name' => trim((string) $user->first_name)] : [];

        $data = ['profile' => $profile ? CommunityResource::profile($profile, $user) : null, 'venue_ids' => $profile?->venues()->pluck('venues.id')->all() ?? [],
            'venues' => $venues->map(fn ($v) => ['id' => $v->id, 'name' => $v->name])->all(), 'verified' => $this->access->state($user)['eligible'],
            'access' => $this->access->state($user), 'suggested' => $proposta,
            'cities' => City::query()->active()->get(['id', 'name'])->toArray()];
        if ($request->expectsJson()) {
            return ApiResponse::item($data);
        }

        return view('community.settings', [...$data, 'suggested' => $proposta, 'cities' => City::query()->active()->get(), 'meta' => new PageMeta(__('community.settings'), __('community.settings'), indexable: false)]);
    }

    /**
     * Se un nome utente è libero, mentre lo si sta scrivendo.
     *
     * Le regole arrivano da `ProfileRequest::handleRules()`: chiedere due volte
     * la stessa cosa a due posti diversi è il modo sicuro per farli divergere.
     * Non espone niente che il salvataggio non esponga già — «questo nome è
     * preso» lo diceva comunque l'errore di convalida — ma lo dice prima.
     */
    public function handleAvailability(Request $request): JsonResponse
    {
        $handle = mb_strtolower(trim($request->string('handle')->toString()));

        $validator = Validator::make(['handle' => $handle],
            ['handle' => ProfileRequest::handleRules($request->user()?->communityProfile?->id)]);

        $libero = $handle !== '' && $validator->passes();

        return response()->json(['data' => [
            'handle' => $handle,
            'available' => $libero,
            // Vuoto o valido: nessun motivo da dare. `first()` restituirebbe una
            // stringa vuota, che in una risposta JSON è un motivo che non c'è.
            'reason' => $libero || $handle === '' ? null : $validator->errors()->first('handle'),
        ]]);
    }

    public function update(ProfileRequest $request): JsonResponse|RedirectResponse
    {
        $profile = $this->community->profile($request->user(), $request->validated());
        if ($request->boolean('remove_avatar')) {
            $profile->clearMediaCollection('avatar');
        }
        if ($request->hasFile('avatar')) {
            // Decode/re-encode: remove EXIF, embedded metadata and executable trailing bytes.
            $image = imagecreatefromstring(file_get_contents($request->file('avatar')->getRealPath()));
            abort_unless($image !== false, 422);
            ob_start();
            imagejpeg($image, null, 85);
            $bytes = ob_get_clean();
            imagedestroy($image);
            $profile->addMediaFromString($bytes)->usingFileName('avatar.jpg')->toMediaCollection('avatar');
        }
        if ($request->expectsJson()) {
            return ApiResponse::item(CommunityResource::profile($profile->refresh(), $request->user()));
        }

        return redirect()->intended(route('community.settings'))->with('status', __('community.updated'));
    }

    public function follow(Request $request, User $user): JsonResponse|RedirectResponse
    {
        $follow = ! $request->isMethod('DELETE');
        $this->community->follow($request->user(), $user, $follow);

        return $this->done($request, $follow ? 'community.followed' : 'community.unfollowed');
    }

    public function block(Request $request, User $user): JsonResponse|RedirectResponse
    {
        $block = ! $request->isMethod('DELETE');
        $this->community->block($request->user(), $user, $block);
        $status = $block ? 'community.blocked' : 'community.unblocked';
        if (! $request->expectsJson()) {
            return redirect()->route('community.followers')->with('status', __($status));
        }

        return $this->done($request, $status);
    }

    public function followers(CommunityQueryRequest $request): View|JsonResponse
    {
        $result = $this->community->followers($request->user());
        $followers = $result['page'];
        $following = $this->access->profiles($request->user())->whereIn('user_id', $request->user()->followings()->where('followable_type', 'user')->whereNotNull('accepted_at')->select('followable_id'))->orderBy('display_name')->paginate(30, ['*'], 'following_page');
        $data = ['followers' => $result['followers'], 'blocks' => $result['blocks'], 'tab' => $request->validated('tab', 'following'),
            'following' => $following->getCollection()->map(fn ($profile) => ['user_id' => $profile->user_id, 'display_name' => $profile->display_name, 'handle' => $profile->handle])->all(),
            'following_has_more' => $following->hasMorePages(), 'following_next_page' => $following->hasMorePages() ? $following->currentPage() + 1 : null];
        if ($request->expectsJson()) {
            return ApiResponse::item([...$data, 'has_more' => $followers->hasMorePages(), 'next_page' => $followers->hasMorePages() ? $followers->currentPage() + 1 : null]);
        }

        return view('community.followers', [...$data, 'pagination' => $request->validated('tab', 'following') === 'following' ? $following->withQueryString() : $followers->withQueryString(), 'meta' => new PageMeta(__('community.followers'), __('community.followers'), indexable: false)]);
    }

    public function compose(Request $request, int $occurrence): View|JsonResponse
    {
        $user = $request->user();
        $date = EventOccurrence::with('event.city')->findOrFail($occurrence);
        $post = CommunityPost::query()->where('user_id', $user->id)->where('occurrence_id', $occurrence)->first();
        abort_unless($post !== null || EventOccurrenceQuery::archiveFor($date->event->city)->identifiersQuery()->where('event_occurrences.id', $occurrence)->exists(), 404);
        $saved = $user->savedEvents()->where('occurrence_id', $occurrence)->first() ?? new SavedEvent(['occurrence_id' => $occurrence]);
        abort_unless($saved->exists || $post !== null, 404);
        $saved->setRelation('occurrence', $date);
        $data = ['visibility' => $post === null ? 'private' : 'public', 'body' => $post?->body,
            'intent' => $post?->intent->value ?? PostIntent::Recommend->value, 'post_id' => $post?->id, 'access' => $this->access->state($user)];
        if ($request->expectsJson()) {
            return ApiResponse::item($data);
        }

        return view('community.compose', [...$data, 'saved' => $saved, 'post' => $post, 'meta' => new PageMeta(__('community.publish'), __('community.publish'), indexable: false)]);
    }

    public function publish(PublicationRequest $request, int $occurrence): JsonResponse|RedirectResponse
    {
        $post = $this->community->publication($request->user(), $occurrence, $request->validated());
        if ($request->expectsJson()) {
            return ApiResponse::item(['post_id' => $post?->id, 'visibility' => $request->input('visibility')]);
        }

        return back()->with('status', __('community.updated'));
    }

    /**
     * «Ci vado» e il passo indietro. Un solo gesto, un solo interruttore: la
     * pagina di pubblicazione con testo e intento resta dov'è, per chi vuole
     * dire qualcosa in più.
     */
    public function attendanceState(Request $request, EventOccurrence $occurrence): JsonResponse
    {
        abort_unless(EventOccurrenceQuery::archiveFor($occurrence->event->city)->identifiersQuery()->where('event_occurrences.id', $occurrence->id)->exists(), 404);
        $user = $this->viewer($request);
        $access = $this->access->state($user);
        $people = $this->access->attendees($occurrence, $user);

        return ApiResponse::item(['access' => $access,
            'going' => $user !== null && DB::table('community_attendances')->where('user_id', $user->id)->where('occurrence_id', $occurrence->id)->exists(),
            'count' => (clone $people)->count(),
            'attendees' => $access['eligible'] ? $people->limit(12)->get()->map(fn ($person) => ['display_name' => $person->communityProfile->display_name, 'handle' => $person->communityProfile->handle])->all() : []]);
    }

    public function attendance(AttendanceRequest $request, EventOccurrence $occurrence): JsonResponse|RedirectResponse
    {
        $public = $this->community->attendance($request->user(), $occurrence, $request->going());

        if ($request->expectsJson()) {
            return ApiResponse::item(['going' => $public,
                'count' => app(CommunityAccess::class)->attendees($occurrence, $request->user())->count()]);
        }

        return back()->with('status', __('community.updated'));
    }

    public function comment(CommentRequest $request, CommunityPost $post): JsonResponse|RedirectResponse
    {
        $comment = $this->community->comment($request->user(), $post, $request->string('body')->toString(), $request->filled('parent_id') ? $request->integer('parent_id') : null);
        if ($request->expectsJson()) {
            return ApiResponse::item(CommunityResource::comment($comment, $request->user()), status: 201);
        }

        return back()->with('status', __('community.updated'));
    }

    public function deleteComment(Request $request, CommunityComment $comment): JsonResponse|RedirectResponse
    {
        $this->community->deleteComment($request->user(), $comment);

        return $this->done($request, 'community.comment_deleted');
    }

    public function report(CommunityReportRequest $request): JsonResponse|RedirectResponse
    {
        $this->community->report($request->user(), $request->string('type')->toString(), $request->integer('id'), $request->validated());

        return $this->done($request, 'community.reported');
    }

    /** Il JSON resta {ok: true}; sul sito il messaggio dice che cosa è successo. */
    private function done(Request $request, string $status = 'community.updated'): JsonResponse|RedirectResponse
    {
        return $request->expectsJson() ? ApiResponse::item(['ok' => true]) : back()->with('status', __($status));
    }
}
