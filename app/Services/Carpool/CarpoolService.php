<?php

declare(strict_types=1);

namespace App\Services\Carpool;

use App\Enums\RideLeg;
use App\Enums\RideRequestStatus;
use App\Enums\RideStatus;
use App\Models\CarpoolProfile;
use App\Models\EventOccurrence;
use App\Models\RideConversation;
use App\Models\RideOffer;
use App\Models\RideRequest;
use App\Models\RideSearch;
use App\Models\User;
use App\Queries\CarpoolQuery;
use Illuminate\Support\Facades\DB;
use Musonza\Chat\Models\Conversation;

final class CarpoolService
{
    public function __construct(private CarpoolAccess $access, private CarpoolQuery $clock, private CarpoolAuditLog $audit, private CommunityNotices $notices) {}

    /** @param array<string, mixed> $data
     * @return array{entity: string, id: int}
     */
    public function execute(User $actor, string $action, array $data): array
    {
        $offer = isset($data['offer_id']) ? RideOffer::findOrFail($data['offer_id']) : null;
        $request = isset($data['request_id']) ? RideRequest::findOrFail($data['request_id']) : null;
        $dateId = $offer->occurrence_id ?? $request?->offer->occurrence_id ?? ($data['occurrence_id'] ?? null);
        $key = (string) $data['request_key'];
        unset($data['request_key']);
        $entity = match ($action) {
            'declare', 'revoke-adult', 'preferences' => 'profile', 'request', 'accept', 'decline', 'withdraw', 'reduce' => 'request', default => 'offer'
        };
        $id = app(CarpoolCommands::class)->run($actor, $key, $action, $data, $dateId === null ? null : (int) $dateId, function (User $user) use ($action, $data): int {
            return match ($action) {
                'declare' => $this->declare($user, $data),
                'revoke-adult' => $this->revokeAdult($user),
                'preferences' => $this->preferences($user, $data),
                'offer' => $this->create($user, $data),
                'update' => $this->update($user, $data),
                'publish', 'close', 'reopen', 'cancel' => $this->changeOffer($user, $action, $data),
                'request' => $this->request($user, $data),
                'accept', 'decline', 'withdraw', 'reduce' => $this->decide($user, $action, $data),
                default => abort(404),
            };
        });

        return ['entity' => $entity, 'id' => $id];
    }

    /** @param array<string, mixed> $data */
    private function declare(User $user, array $data): int
    {
        abort_unless($this->access->contacts($user), 403);
        abort_unless($data['version'] === config('carpool.terms_version'), 409, __('carpool.errors.terms'));
        $profile = CarpoolProfile::firstOrCreate(['user_id' => $user->id]);
        $profile->forceFill(['adult_declared_at' => $profile->adult_declared_at ?? now(), 'adult_version' => '18-plus-v1',
            'terms_version' => $data['version'], 'terms_hash' => app(CarpoolTerms::class)->hash(), 'terms_accepted_at' => now()])->save();
        $this->audit->record($user, 'adult_and_terms_accepted', $profile, ['version' => $data['version'], 'hash' => $profile->terms_hash]);

        return $profile->id;
    }

    private function revokeAdult(User $user): int
    {
        $profile = CarpoolProfile::firstOrCreate(['user_id' => $user->id]);
        $profile->update(['adult_declared_at' => null, 'driver_declared_at' => null]);
        $this->audit->record($user, 'adult_declaration_revoked', $profile);

        // Mutation endpoints immediately recheck eligibility; reconciliation also closes existing agreements.
        return $profile->id;
    }

    /** @param array<string, mixed> $data */
    private function preferences(User $user, array $data): int
    {
        $profile = CarpoolProfile::firstOrCreate(['user_id' => $user->id]);
        $profile->update(['push_enabled' => (bool) $data['push_enabled']]);

        return $profile->id;
    }

    /** @param array<string, mixed> $data */
    private function create(User $user, array $data): int
    {
        $this->access->requireEligible($user);
        abort_unless(config('carpool.new_rides'), 409, __('carpool.errors.closed'));
        $date = EventOccurrence::findOrFail($data['occurrence_id']);
        $leg = RideLeg::from($data['leg']);
        $departure = $this->clock->departure($data['departure_at'], $date);
        abort_unless($this->clock->allowed($date, $leg, $departure), 422, __('carpool.errors.departure'));
        $draft = (bool) ($data['draft'] ?? false);
        abort_if(RideOffer::where('driver_id', $user->id)->where('status', RideStatus::Draft)->count() >= 20, 422, __('carpool.errors.limit'));
        if (! $draft) {
            $this->requireFree($user, $date->id, $leg);
        }
        $offer = RideOffer::create(['driver_id' => $user->id, 'occurrence_id' => $date->id, 'leg' => $leg,
            'status' => $draft ? RideStatus::Draft : RideStatus::Open, 'active_key' => $draft ? null : $this->occupancyKey($user, $date->id, $leg),
            'zone' => $data['zone'], 'departure_at' => $departure, 'capacity' => $data['capacity'],
            'accessibility' => $data['accessibility'] ?? 'not_specified', 'accessibility_note' => $data['accessibility_note'] ?? null,
            'note' => $data['note'] ?? null, 'stops' => $data['stops'] ?? [], 'snapshot' => $this->clock->snapshot($date)]);
        if (! $draft) {
            $this->occupy($user, $offer);
        }
        $this->access->profile($user)?->update(['driver_declared_at' => now()]);
        $this->audit->record($user, $draft ? 'ride_drafted' : 'ride_offered', $offer, ['capacity' => $offer->capacity, 'accessibility' => $offer->accessibility->value]);

        return $offer->id;
    }

    /** @param array<string, mixed> $data */
    private function update(User $user, array $data): int
    {
        $this->access->requireEligible($user);
        $offer = RideOffer::whereKey($data['offer_id'])->lockForUpdate()->firstOrFail();
        abort_unless($offer->driver_id === $user->id, 403);
        abort_unless(in_array($offer->status, [RideStatus::Draft, RideStatus::Open, RideStatus::Closed], true) && $this->clock->future($offer), 409, __('carpool.errors.closed'));
        abort_unless($offer->revision === (int) $data['revision'], 409, __('carpool.errors.changed'));
        $changes = array_intersect_key($data, array_flip(['zone', 'capacity', 'accessibility', 'accessibility_note', 'note', 'stops']));
        if (isset($data['departure_at'])) {
            $changes['departure_at'] = $this->clock->departure($data['departure_at'], $offer->occurrence);
            abort_unless($this->clock->allowed($offer->occurrence, $offer->leg, $changes['departure_at']), 422, __('carpool.errors.departure'));
        }
        $occupied = $this->occupied($offer);
        abort_if(isset($changes['capacity']) && $changes['capacity'] < $occupied, 409, __('carpool.errors.capacity'));
        $offer->fill($changes);
        $material = $offer->isDirty(['zone', 'departure_at', 'accessibility', 'accessibility_note', 'stops', 'note']);
        abort_if($material && $occupied > 0, 409, __('carpool.errors.agreement'));
        if ($material) {
            $offer->revision++;
            foreach ($offer->requests()->where('status', RideRequestStatus::Pending)->lockForUpdate()->get() as $request) {
                $this->finish($request, RideRequestStatus::Cancelled, 'offer_changed', $user);
            }
        }
        $offer->save();
        $this->audit->record($user, 'ride_updated', $offer, ['revision' => $offer->revision, 'capacity' => $offer->capacity]);

        return $offer->id;
    }

    /** @param array<string, mixed> $data */
    private function changeOffer(User $user, string $action, array $data): int
    {
        $offer = RideOffer::whereKey($data['offer_id'])->lockForUpdate()->firstOrFail();
        abort_unless($offer->driver_id === $user->id, 403);
        if ($action === 'cancel') {
            abort_unless(in_array($offer->status, [RideStatus::Draft, RideStatus::Cancelled], true) || $this->clock->future($offer), 409, __('carpool.errors.departed'));
            $this->cancelOffer($offer, $user, 'driver_cancelled');

            return $offer->id;
        }
        $this->access->requireEligible($user);
        abort_unless($this->clock->future($offer) && $this->clock->visible($offer->occurrence) && ! $this->clock->changed($offer), 409, __('carpool.errors.closed'));
        if ($action === 'publish') {
            abort_unless(config('carpool.new_rides') && $offer->status === RideStatus::Draft, 409);
            $this->requireFree($user, $offer->occurrence_id, $offer->leg);
            $offer->update(['status' => RideStatus::Open, 'active_key' => $this->occupancyKey($user, $offer->occurrence_id, $offer->leg)]);
            $this->occupy($user, $offer);
        } else {
            abort_unless(in_array($offer->status, [RideStatus::Open, RideStatus::Closed], true), 409);
            $offer->update(['status' => $action === 'close' ? RideStatus::Closed : RideStatus::Open]);
        }
        $this->audit->record($user, 'ride_'.$action, $offer);

        return $offer->id;
    }

    /** @param array<string, mixed> $data */
    private function request(User $user, array $data): int
    {
        $this->access->requireEligible($user);
        abort_unless(config('carpool.new_rides'), 409, __('carpool.errors.closed'));
        $offer = RideOffer::whereKey($data['offer_id'])->lockForUpdate()->firstOrFail();
        abort_unless($this->access->canViewOffer($user, $offer), 404);
        // Un passaggio dimostrativo si guarda soltanto: nessuno risponderebbe alla richiesta.
        abort_if($offer->isDemo(), 409, __('carpool.errors.demo'));
        abort_unless($offer->driver_id !== $user->id && ! $this->access->blocked($user, $offer->driver), 403);
        $this->access->requireEligible($offer->driver, false);
        abort_unless($offer->status === RideStatus::Open && $this->clock->operational($offer), 409, __('carpool.errors.closed'));
        abort_unless($offer->revision === (int) $data['revision'], 409, __('carpool.errors.changed'));
        $this->requireFree($user, $offer->occurrence_id, $offer->leg);
        abort_unless((int) $data['seats'] <= $this->available($offer), 409, __('carpool.errors.capacity'));
        abort_if(RideRequest::where('active_key', $user->id.':'.$offer->id)->exists(), 409, __('carpool.errors.duplicate'));
        $pending = RideRequest::where('user_id', $user->id)->where('status', RideRequestStatus::Pending)
            ->whereHas('offer', fn ($q) => $q->where('occurrence_id', $offer->occurrence_id)->where('leg', $offer->leg))->count();
        abort_if($pending >= config()->integer('carpool.max_pending'), 422, __('carpool.errors.limit'));
        if (isset($data['stop_index'])) {
            abort_unless(isset(($offer->stops ?? [])[$data['stop_index']]), 422, __('carpool.errors.stop'));
        }
        $request = RideRequest::create(['ride_offer_id' => $offer->id, 'user_id' => $user->id, 'seats' => $data['seats'],
            'companions_adult' => $data['companions_adult'] ?? false, 'note' => $data['note'] ?? null, 'stop_index' => $data['stop_index'] ?? null,
            'offer_revision' => $offer->revision, 'status' => RideRequestStatus::Pending, 'active_key' => $user->id.':'.$offer->id]);
        $this->audit->record($user, 'seats_requested', $request, ['seats' => $request->seats]);
        $this->notices->ride($offer->driver, 'requested', $request);

        return $request->id;
    }

    /** @param array<string, mixed> $data */
    private function decide(User $user, string $action, array $data): int
    {
        $request = RideRequest::whereKey($data['request_id'])->lockForUpdate()->firstOrFail();
        $offer = RideOffer::whereKey($request->ride_offer_id)->lockForUpdate()->firstOrFail();
        abort_unless(in_array($action, ['accept', 'decline'], true) ? $offer->driver_id === $user->id : $request->user_id === $user->id, 403);
        if ($action === 'withdraw') {
            if (in_array($request->status, [RideRequestStatus::Pending, RideRequestStatus::Accepted], true)) {
                abort_unless($this->clock->future($offer), 409, __('carpool.errors.departed'));
                $this->finish($request, RideRequestStatus::Withdrawn, 'passenger_withdrew', $user);
            }

            return $request->id;
        }
        $this->access->requireEligible($user, $action === 'accept');
        if ($action === 'reduce') {
            abort_unless($request->status === RideRequestStatus::Accepted && $this->clock->future($offer), 409);
            abort_unless((int) $data['seats'] < $request->seats, 422, __('carpool.errors.reduction'));
            $request->update(['seats' => $data['seats']]);
            $this->audit->record($user, 'seats_reduced', $request, ['seats' => $request->seats]);
            $this->notices->ride($offer->driver, 'reduced', $request, ':'.$request->seats);

            return $request->id;
        }
        abort_unless($request->status === RideRequestStatus::Pending && $this->clock->operational($offer), 409, __('carpool.errors.closed'));
        if ($action === 'decline') {
            $this->finish($request, RideRequestStatus::Declined, 'driver_declined', $user);

            return $request->id;
        }
        $passenger = User::whereKey($request->user_id)->lockForUpdate()->firstOrFail();
        $this->access->requireEligible($passenger);
        abort_unless(! $this->access->blocked($user, $passenger), 403);
        abort_unless($offer->revision === $request->offer_revision, 409, __('carpool.errors.changed'));
        abort_unless($request->seats <= $this->available($offer), 409, __('carpool.errors.capacity'));
        $this->requireFree($passenger, $offer->occurrence_id, $offer->leg);
        $request->update(['status' => RideRequestStatus::Accepted, 'accepted_at' => now()]);
        $this->occupy($passenger, $offer, $request);
        $conversation = Conversation::create(['direct_message' => false, 'data' => ['purpose' => 'event_ride']]);
        $conversation->addParticipants([$user, $passenger]);
        $chat = RideConversation::create(['ride_request_id' => $request->id, 'conversation_id' => $conversation->id]);
        foreach ([$user, $passenger] as $participant) {
            DB::table('ride_chat_preferences')->insert(['ride_conversation_id' => $chat->id, 'user_id' => $participant->id]);
        }
        foreach (RideRequest::where('user_id', $passenger->id)->whereKeyNot($request->id)->where('status', RideRequestStatus::Pending)
            ->whereHas('offer', fn ($q) => $q->where('occurrence_id', $offer->occurrence_id)->where('leg', $offer->leg))->lockForUpdate()->get() as $alternative) {
            $this->finish($alternative, RideRequestStatus::Withdrawn, 'alternative_accepted', $passenger);
        }
        RideSearch::where('user_id', $passenger->id)->where('occurrence_id', $offer->occurrence_id)->where('leg', $offer->leg)->update(['active' => false]);
        $this->audit->record($user, 'request_accepted', $request, ['seats' => $request->seats]);
        $this->notices->ride($passenger, 'accepted', $request);

        return $request->id;
    }

    public function occupied(RideOffer $offer): int
    {
        return (int) $offer->requests()->where('status', RideRequestStatus::Accepted)->sum('seats');
    }

    public function available(RideOffer $offer): int
    {
        return max(0, $offer->capacity - $this->occupied($offer));
    }

    public function cancelOffer(RideOffer $offer, ?User $actor, string $reason): void
    {
        if (in_array($offer->status, [RideStatus::Cancelled, RideStatus::Completed], true)) {
            return;
        }
        foreach ($offer->requests()->whereIn('status', [RideRequestStatus::Pending, RideRequestStatus::Accepted])->lockForUpdate()->get() as $request) {
            $this->finish($request, RideRequestStatus::Cancelled, $reason, $actor);
        }
        $offer->update(['status' => RideStatus::Cancelled, 'active_key' => null, 'closed_at' => now(), 'close_reason' => $reason]);
        DB::table('ride_occupancies')->where('ride_offer_id', $offer->id)->delete();
        $this->audit->record($actor, 'ride_cancelled', $offer, ['reason' => $reason]);
    }

    public function finish(RideRequest $request, RideRequestStatus $status, string $reason, ?User $actor): void
    {
        if (! in_array($request->status, [RideRequestStatus::Pending, RideRequestStatus::Accepted], true)) {
            return;
        }
        $request->update(['status' => $status, 'active_key' => null, 'closed_at' => now(), 'close_reason' => $reason]);
        DB::table('ride_occupancies')->where('ride_request_id', $request->id)->delete();
        RideConversation::where('ride_request_id', $request->id)->update(['read_only_at' => now()]);
        $this->audit->record($actor, 'request_'.$status->value, $request, ['reason' => $reason]);
        foreach ([$request->user, $request->offer->driver] as $recipient) {
            if ($recipient && $recipient->id !== $actor?->id) {
                $this->notices->ride($recipient, $status->value, $request);
            }
        }
    }

    private function requireFree(User $user, int $dateId, RideLeg $leg): void
    {
        abort_if(DB::table('ride_occupancies')->where('user_id', $user->id)->where('occurrence_id', $dateId)->where('leg', $leg->value)->exists(), 409, __('carpool.errors.occupied'));
    }

    private function occupy(User $user, RideOffer $offer, ?RideRequest $request = null): void
    {
        DB::table('ride_occupancies')->insert(['user_id' => $user->id, 'occurrence_id' => $offer->occurrence_id,
            'leg' => $offer->leg->value, 'ride_offer_id' => $offer->id, 'ride_request_id' => $request?->id]);
    }

    private function occupancyKey(User $user, int $dateId, RideLeg $leg): string
    {
        return $user->id.':'.$dateId.':'.$leg->value;
    }
}
