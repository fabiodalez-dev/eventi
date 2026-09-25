<?php

declare(strict_types=1);

namespace App\Services\Carpool;

use App\Enums\RideStatus;
use App\Models\CarpoolProfile;
use App\Models\RideConversation;
use App\Models\RideOffer;
use App\Models\RideRequest;
use App\Models\User;
use App\Queries\CarpoolQuery;
use App\Services\Community\CommunityAccess;

final class CarpoolAccess
{
    private function isAdministrator(User $user): bool
    {
        return $user->isWhatsappExempt();
    }

    public function contacts(User $user): bool
    {
        return $user->hasVerifiedEmail()
            && ($this->isAdministrator($user) || ($user->whatsapp_verified_at !== null && $user->whatsapp_phone_hash !== null))
            && $user->community_suspended_at === null && $user->carpool_suspended_at === null && ! $user->trashed();
    }

    public function profile(User $user): ?CarpoolProfile
    {
        return $user->carpoolProfile;
    }

    public function eligible(User $user, bool $newAgreement = true): bool
    {
        $profile = $this->profile($user);

        return $this->contacts($user) && $profile?->adult_declared_at !== null
            && (! $newAgreement || $profile->terms_version === config('carpool.terms_version'));
    }

    public function requireEligible(User $user, bool $newAgreement = true): void
    {
        $this->notImpersonating();
        abort_unless(config('carpool.enabled') && $this->eligible($user, $newAgreement), 403, __('carpool.errors.eligibility'));
    }

    public function notImpersonating(): void
    {
        abort_if(request()->hasSession() && request()->session()->has('impersonator_id'), 403, __('carpool.errors.impersonation'));
    }

    public function blocked(User $a, User $b): bool
    {
        return app(CommunityAccess::class)->blocked($a, $b);
    }

    public function participant(User $user, RideRequest $request): bool
    {
        return $user->id === $request->user_id || $user->id === $request->offer->driver_id;
    }

    public function canViewOffer(User $user, RideOffer $offer): bool
    {
        if ($offer->driver_id === $user->id || $offer->requests()->where('user_id', $user->id)->exists()) {
            return true;
        }

        return $this->eligible($user) && $offer->driver !== null && $this->eligible($offer->driver, false)
            && ! $this->blocked($user, $offer->driver) && $offer->status === RideStatus::Open
            && app(CarpoolQuery::class)->operational($offer);
    }

    public function canReadChat(User $user, RideConversation $chat): bool
    {
        $this->notImpersonating();

        return ! $user->trashed() && $user->community_suspended_at === null && $user->carpool_suspended_at === null
            && app(CarpoolRetention::class)->readable($chat) && $this->participant($user, $chat->rideRequest) && $chat->hidden_at === null && $chat->purged_at === null;
    }

    /** @return array<string, mixed> */
    public function state(?User $user): array
    {
        $profile = $user ? $this->profile($user) : null;
        $reason = match (true) {
            $user === null => 'login',
            $user->community_suspended_at !== null || $user->carpool_suspended_at !== null => 'suspended',
            ! $user->hasVerifiedEmail() => 'email',
            ! $this->contacts($user) => 'whatsapp',
            $profile?->adult_declared_at === null => 'adult',
            $profile->terms_version !== config('carpool.terms_version') => 'terms',
            default => null,
        };

        return ['whatsapp_exempt' => $user?->isWhatsappExempt() ?? false, 'whatsapp_verified' => $user?->isWhatsappVerified() ?? false, 'eligible' => $reason === null, 'reason' => $reason, 'adult_declared_at' => $profile?->adult_declared_at?->toIso8601String(),
            'terms_version' => config('carpool.terms_version'), 'accepted_version' => $profile?->terms_version,
            'push_enabled' => $profile->push_enabled ?? true];
    }
}
