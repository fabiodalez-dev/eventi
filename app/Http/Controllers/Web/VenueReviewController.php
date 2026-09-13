<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\VenueStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Http\Requests\Reviews\StoreVenueReviewRequest;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueReview;
use App\Services\Reviews\VenueReviews;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VenueReviewController extends Controller
{
    use InteractsWithCity;

    public function index(Request $request, string $slug, VenueReviews $reviews): JsonResponse
    {
        $venue = $this->venue($slug);
        $user = $request->user('sanctum');

        return ApiResponse::item($reviews->listing($venue, $user instanceof User ? $user : null, max(1, $request->integer('page', 1))))
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(StoreVenueReviewRequest $request, string $slug, VenueReviews $reviews): JsonResponse|RedirectResponse
    {
        $venue = $this->venue($slug);
        abort_unless($venue->status === VenueStatus::Approved, 403);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $reviews->submit($venue, $user, (int) $request->validated('rating'), (string) $request->validated('body'));

        return $request->is('api/*')
            ? ApiResponse::item(['message' => __('reviews.submitted')])->header('Cache-Control', 'private, no-store')
            : redirect()->route('venues.show', ['slug' => $slug])->withFragment('recensioni')->with('status', __('reviews.submitted'));
    }

    public function destroy(Request $request, string $slug): JsonResponse|RedirectResponse
    {
        $venue = $this->venue($slug);
        $review = VenueReview::query()->where('venue_id', $venue->id)->where('user_id', $request->user()?->id)->firstOrFail();
        $review->delete();

        return $request->is('api/*')
            ? ApiResponse::item(['message' => __('reviews.deleted')])->header('Cache-Control', 'private, no-store')
            : redirect()->route('venues.show', ['slug' => $slug])->withFragment('recensioni')->with('status', __('reviews.deleted'));
    }

    private function venue(string $slug): Venue
    {
        return Venue::query()->inCity($this->city())->where('slug', $slug)->whereIn('status', [VenueStatus::Approved, VenueStatus::Suspended])->firstOrFail();
    }
}
