<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Account\AssignCheckinStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ticketing\DecisionDetailsRequest;
use App\Http\Requests\Ticketing\MobileManagementRequest;
use App\Http\Requests\Ticketing\StaffRequest;
use App\Models\Booking;
use App\Models\EventOccurrence;
use App\Services\Sponsorship\CampaignEconomics;
use App\Services\Ticketing\MobileManagement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class MobileManagementController extends Controller
{
    public function index(MobileManagementRequest $request, MobileManagement $management): JsonResponse
    {
        $past = $request->validated('period') === 'past';
        $search = $request->validated('q');
        $dates = $management->dates($request->user())->with(['event.venue', 'venue'])
            ->whereRaw('COALESCE(effective_ends_at, ends_at, starts_at) '.($past ? '<' : '>=').' ?', [now()])
            ->when($search, fn ($q) => $q->whereHas('event', fn ($e) => $e->where('title', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->orderBy('starts_at', $past ? 'desc' : 'asc')->orderBy('id')->paginate(30);

        return response()->json(['data' => $dates->getCollection()->map(fn ($date) => $management->date($date, $request->user())), 'meta' => ['next_page' => $dates->hasMorePages() ? $dates->currentPage() + 1 : null]]);
    }

    public function show(Request $request, EventOccurrence $occurrence, MobileManagement $management): JsonResponse
    {
        Gate::authorize('checkIn', [Booking::class, $occurrence]);

        return response()->json(['data' => $management->date($occurrence, $request->user(), true)]);
    }

    public function staff(StaffRequest $request, EventOccurrence $occurrence, AssignCheckinStaff $assign): JsonResponse
    {
        $assign($occurrence, $request->validated('email'), $request->boolean('remove'), $request->user());

        return response()->json(['data' => ['message' => __('decision.staff_saved')]]);
    }

    public function details(DecisionDetailsRequest $request, EventOccurrence $occurrence, MobileManagement $management): JsonResponse
    {
        $management->updateDetails($occurrence, $request->user(), $request->validated());

        return response()->json(['data' => ['message' => __('ticketing.updated')]]);
    }

    public function campaigns(MobileManagementRequest $request, MobileManagement $management): JsonResponse
    {
        $campaigns = $management->campaigns($request->user())->with('event')->latest('id')->paginate(30);

        return response()->json(['data' => $campaigns->getCollection()->map(fn ($c) => ['id' => $c->id, 'title' => $c->event?->title, 'starts_at' => $c->starts_at->toIso8601String(), 'ends_at' => $c->ends_at->toIso8601String()]), 'meta' => ['next_page' => $campaigns->hasMorePages() ? $campaigns->currentPage() + 1 : null]]);
    }

    public function economics(Request $request, int $campaign, MobileManagement $management, CampaignEconomics $economics): JsonResponse
    {
        $record = $management->campaigns($request->user())->with('event')->findOrFail($campaign);

        return response()->json(['data' => ['id' => $record->id, 'title' => $record->event?->title, 'starts_at' => $record->starts_at->toIso8601String(), 'ends_at' => $record->ends_at->toIso8601String(), 'economics' => $economics->for($record)]]);
    }
}
