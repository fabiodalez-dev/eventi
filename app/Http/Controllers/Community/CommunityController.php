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
use App\Http\Resources\V1\CommunityResource;
use App\Http\Resources\V1\VenueResource;
use App\Models\City;
use App\Models\CommunityComment;
use App\Models\CommunityPost;
use App\Models\CommunityProfile;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
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

    public function profile(CommunityQueryRequest $request, string $handle): View|JsonResponse
    {
        $user = $this->viewer($request);
        $profile = $this->access->profiles($user)->where('handle', $handle)->first();
        if ($profile === null && $user?->isWhatsappVerified()) {
            $profile = $user->communityProfile()->where('handle', $handle)->first();
        }
        abort_unless($profile !== null, 404);
        $city = $profile->city ?? $this->city();
        // L'archivio del profilo è fatto solo di date concluse, la vista normale solo di quelle a venire.
        $posts = $this->access->posts($user, $city, ! $request->boolean('past'))->where('user_id', $profile->user_id)->orderByDesc('published_at')->orderByDesc('id')->paginate(20)->withQueryString();
        $venues = $profile->venues()->where('status', VenueStatus::Approved)->with(['city', 'media'])->get();
        $followingIds = $this->access->followingIds($user, [$profile->user_id]);
        if ($request->expectsJson()) {
            $context = ApiContext::forOccurrences($city, [], $user, $posts->pluck('occurrence_id')->all());

            return ApiResponse::item(['profile' => CommunityResource::profile($profile, $user, $followingIds), 'posts' => $posts->getCollection()->map(fn ($p) => CommunityResource::post($p, $user, $context, $followingIds))->all(),
                'venues' => $venues->map(fn ($v) => VenueResource::summary($v))->all(), 'has_more' => $posts->hasMorePages(), 'next_page' => $posts->hasMorePages() ? $posts->currentPage() + 1 : null]);
        }

        return view('community.profile', ['profile' => $profile, 'summary' => CommunityResource::profile($profile, $user, $followingIds), 'posts' => $posts, 'venues' => $venues,
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
        $canComment = $user?->isWhatsappVerified() && $user->communityProfile !== null;

        return view('community.post', ['post' => $post, 'comments' => $comments, 'visibleProfileIds' => $visibleProfileIds, 'canComment' => $canComment, 'meta' => new PageMeta(__('community.post'), __('community.post'), indexable: false)]);
    }

    public function settings(Request $request): View|JsonResponse
    {
        $user = $request->user();
        $profile = $user->communityProfile;
        $venues = Venue::query()->where('status', VenueStatus::Approved)->whereIn('id', $user->follows()->where('followable_type', 'venue')->select('followable_id'))->orderBy('name')->get();
        $data = ['profile' => $profile ? CommunityResource::profile($profile, $user) : null, 'venue_ids' => $profile?->venues()->pluck('venues.id')->all() ?? [],
            'venues' => $venues->map(fn ($v) => ['id' => $v->id, 'name' => $v->name])->all(), 'verified' => $user->isWhatsappVerified(),
            'cities' => City::query()->active()->get(['id', 'name'])->toArray()];
        if ($request->expectsJson()) {
            return ApiResponse::item($data);
        }

        return view('community.settings', [...$data, 'cities' => City::query()->active()->get(), 'meta' => new PageMeta(__('community.settings'), __('community.settings'), indexable: false)]);
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

        return back()->with('status', __('community.updated'));
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

    public function followers(Request $request): View|JsonResponse
    {
        $result = $this->community->followers($request->user());
        $followers = $result['page'];
        $data = ['followers' => $result['followers'], 'blocks' => $result['blocks']];
        if ($request->expectsJson()) {
            return ApiResponse::item([...$data, 'has_more' => $followers->hasMorePages(), 'next_page' => $followers->hasMorePages() ? $followers->currentPage() + 1 : null]);
        }

        return view('community.followers', [...$data, 'pagination' => $followers, 'meta' => new PageMeta(__('community.followers'), __('community.followers'), indexable: false)]);
    }

    public function compose(Request $request, int $occurrence): View|JsonResponse
    {
        $saved = $request->user()->savedEvents()->where('occurrence_id', $occurrence)->with('occurrence.event')->firstOrFail();
        $post = CommunityPost::query()->where('saved_event_id', $saved->id)->first();
        if ($request->expectsJson()) {
            return ApiResponse::item(['visibility' => $saved->visibility->value, 'body' => $post?->body, 'intent' => $post?->intent->value ?? PostIntent::Recommend->value, 'post_id' => $post?->id]);
        }

        return view('community.compose', ['saved' => $saved, 'post' => $post, 'meta' => new PageMeta(__('community.publish'), __('community.publish'), indexable: false)]);
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
