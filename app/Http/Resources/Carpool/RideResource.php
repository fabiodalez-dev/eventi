<?php

declare(strict_types=1);

namespace App\Http\Resources\Carpool;

use App\Enums\RideRequestStatus;
use App\Enums\RideStatus;
use App\Models\RideOffer;
use App\Models\RideRequest;
use App\Models\User;
use App\Queries\CarpoolQuery;
use App\Services\Carpool\CarpoolAccess;
use App\Services\Carpool\CarpoolRetention;
use App\Services\Carpool\CarpoolService;
use App\Services\Carpool\RideReviews;

final class RideResource
{
    /** @return array<string, mixed> */
    public static function offer(RideOffer $offer, User $viewer): array
    {
        $date = $offer->occurrence;
        $available = $offer->getAttribute('occupied_seats') !== null ? max(0, $offer->capacity - (int) $offer->getAttribute('occupied_seats')) : app(CarpoolService::class)->available($offer);
        $own = $viewer->id === $offer->driver_id;
        // Il passaggio dimostrativo si mostra come gli altri, senza l'azione di richiesta: l'app legge `demo`.
        $demo = $offer->isDemo();

        return ['id' => $offer->id, 'occurrence_id' => $offer->occurrence_id, 'is_own' => $own,
            'event_title' => $offer->snapshot['title'], 'event_url' => $date?->event ? route('events.occurrence', ['slug' => $date->event->slug, 'occurrence' => $date->url_number]) : null,
            'reviews' => app(RideReviews::class)->summary($viewer, $offer->driver),
            'reviews_url' => route('carpool.reviews', $offer->driver_id),
            'event_location' => $offer->snapshot['location'], 'driver' => ['id' => $offer->driver_id,
                'verified' => app(CarpoolAccess::class)->contacts($offer->driver),
                'name' => $offer->driver?->communityProfile->display_name ?? $offer->driver->name ?? __('community.member')],
            'leg' => $offer->leg->value, 'leg_label' => $offer->leg->label(), 'zone' => $offer->zone,
            'departure_at' => $offer->departure_at->toIso8601String(), 'departure_label' => $offer->departure_at->setTimezone($date->event->city->timezone)->format('d/m/Y H:i'),
            'departure_local' => $offer->departure_at->setTimezone($date->event->city->timezone)->format('Y-m-d\TH:i'),
            'capacity' => $offer->capacity, 'available' => $available, 'revision' => $offer->revision,
            'status' => $offer->status->value, 'status_label' => $offer->status->label(), 'note' => $offer->note,
            'accessibility' => $offer->accessibility->value, 'accessibility_label' => $offer->accessibility->label(),
            'accessibility_note' => $offer->accessibility_note, 'stops' => $offer->stops ?? [],
            'demo' => $demo,
            'can_request' => ! $own && ! $demo && $offer->status === RideStatus::Open && $available > 0 && app(CarpoolQuery::class)->operational($offer),
            'url' => route('carpool.offer', $offer), 'map_url' => 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($offer->zone)];
    }

    /** @return array<string, mixed> */
    public static function request(RideRequest $request, User $viewer): array
    {
        $driver = $request->offer->driver_id === $viewer->id;
        $chat = $request->conversation;
        $future = app(CarpoolQuery::class)->future($request->offer);
        $review = $request->user_id === $viewer->id ? $request->review : null;

        return ['id' => $request->id, 'offer_id' => $request->ride_offer_id, 'seats' => $request->seats,
            'companions' => max(0, $request->seats - 1), 'companions_verified' => false, 'note' => app(CarpoolRetention::class)->requestNoteReadable($request) ? $request->note : null,
            'requester' => ['id' => $request->user_id, 'name' => $request->user?->communityProfile->display_name ?? $request->user->name ?? __('community.member')],
            'status' => $request->status->value, 'status_label' => $request->status->label(), 'is_driver' => $driver,
            'stop_index' => $request->stop_index, 'chat_id' => $chat?->id,
            'can_decide' => $driver && $future && $request->status === RideRequestStatus::Pending,
            'can_withdraw' => ! $driver && $future && in_array($request->status, [RideRequestStatus::Pending, RideRequestStatus::Accepted], true),
            'can_feedback' => $request->accepted_at !== null && ! $future,
            'can_review' => app(RideReviews::class)->eligibleTrip($viewer, $request),
            'passenger_confirmed_at' => $request->passenger_confirmed_at?->toIso8601String(),
            'review_revision' => $review->revision ?? 0, 'review' => $review && ! $review->trashed() ? app(RideReviews::class)->resource($review) : null,
            'url' => route('carpool.request', $request), 'created_at' => $request->created_at->toIso8601String()];
    }
}
