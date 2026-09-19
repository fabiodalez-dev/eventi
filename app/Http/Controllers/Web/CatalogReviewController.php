<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\VenueStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reviews\CatalogReviewQuery;
use App\Http\Requests\Reviews\ReportCatalogReviewRequest;
use App\Http\Requests\Reviews\StoreCatalogReviewRequest;
use App\Models\CatalogReview;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Services\Carpool\CarpoolAccess;
use App\Services\Reviews\CatalogReviews;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CatalogReviewController extends Controller
{
    use Concerns\InteractsWithCity;

    public function index(CatalogReviewQuery $request, string $slug): JsonResponse
    {
        $subject = $this->subject($request, $slug);
        $user = $request->user('sanctum');

        $reviews = app(CatalogReviews::class);

        return ApiResponse::item($reviews->apiListing($reviews->listing($subject, $user instanceof User ? $user : null, $request->integer('page', 1))))
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(StoreCatalogReviewRequest $request, string $slug): JsonResponse|RedirectResponse
    {
        $subject = $this->subject($request, $slug);
        $data = $request->validated();
        app(CatalogReviews::class)->submit($subject, $request->user(), isset($data['rating']) ? (int) $data['rating'] : null, $data['body'] ?? null, isset($data['revision']) ? (int) $data['revision'] : null);

        return $this->result($request, $subject, __('reviews.submitted'));
    }

    public function destroy(Request $request, string $slug): JsonResponse|RedirectResponse
    {
        $subject = $this->subject($request, $slug);
        app(CarpoolAccess::class)->notImpersonating();
        app(CatalogReviews::class)->remove($subject, $request->user());

        return $this->result($request, $subject, __('reviews.deleted'));
    }

    public function report(ReportCatalogReviewRequest $request, string $slug, int $review): JsonResponse|RedirectResponse
    {
        $subject = $this->subject($request, $slug);
        $review = CatalogReview::whereMorphedTo('reviewable', $subject)->findOrFail($review);
        $case = app(CatalogReviews::class)->report($request->user(), $review, $request->validated('body'));

        return $request->expectsJson() ? ApiResponse::item(['case_id' => $case->id]) : redirect()->route('carpool.case', $case->id);
    }

    private function subject(Request $request, string $slug): Venue|Organizer
    {
        return $request->route('type') === 'organizer'
            ? Organizer::visibleInCity($this->city())->where('slug', $slug)->firstOrFail()
            : Venue::inCity($this->city())->where('slug', $slug)->whereIn('status', [VenueStatus::Approved, VenueStatus::Suspended])->firstOrFail();
    }

    private function result(Request $request, Venue|Organizer $subject, string $message): JsonResponse|RedirectResponse
    {
        return $request->expectsJson() ? ApiResponse::item(['message' => $message])->header('Cache-Control', 'private, no-store')
            : redirect()->route($subject instanceof Venue ? 'venues.show' : 'organizers.show', ['slug' => $subject->slug])->withFragment('recensioni')->with('status', $message);
    }
}
