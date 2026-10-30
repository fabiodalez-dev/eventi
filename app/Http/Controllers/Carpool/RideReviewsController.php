<?php

declare(strict_types=1);

namespace App\Http\Controllers\Carpool;

use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Http\Requests\Carpool\CarpoolQueryRequest;
use App\Http\Requests\Carpool\RideReviewRequest;
use App\Models\RideRequest;
use App\Models\User;
use App\Services\Carpool\RideReviews;
use App\Support\PublicName;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class RideReviewsController extends Controller
{
    public function index(CarpoolQueryRequest $request, User $driver, RideReviews $reviews): View|JsonResponse
    {
        // 404 per chi non è un conducente verificato, prima dei controlli su chi legge: la risposta
        // non distingue più un account senza passaggi da uno senza telefono verificato.
        abort_unless($reviews->isDriver($driver), 404);
        abort_unless($reviews->reader($request->user(), $driver), 403);
        $rows = $reviews->published($driver)->with(['user.communityProfile', 'rideRequest'])->orderByDesc('id')->paginate(20);
        $data = ['driver_id' => $driver->id, 'driver_name' => PublicName::for($driver, $request->user()),
            'summary' => $reviews->summary($request->user(), $driver), 'reviews' => $rows->map(fn ($review) => $reviews->resource($review, $request->user()))->all(), 'has_more' => $rows->hasMorePages(), 'page' => $rows->currentPage()];

        return $request->expectsJson() ? response()->json(['data' => $data]) : view('carpool.reviews', [...$data, 'meta' => new PageMeta(__('carpool.reviews.title'), __('carpool.reviews.title'), indexable: false)]);
    }

    public function change(RideReviewRequest $request, RideRequest $rideRequest, string $action, RideReviews $reviews): JsonResponse|RedirectResponse
    {
        $id = $reviews->change($request->user(), $rideRequest, $action, $request->validated());

        return $request->expectsJson() ? response()->json(['data' => ['id' => $id, 'entity' => 'request']]) : redirect()->route('carpool.request', $id)->with('status', __('carpool.saved'));
    }
}
