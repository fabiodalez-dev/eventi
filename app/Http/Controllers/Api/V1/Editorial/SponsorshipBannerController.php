<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Actions\Sponsorship\SaveSponsorshipBanners;
use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Settings\SponsorshipBannerSettings;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Banner sponsorizzati» (R-SP-14): i due interruttori, sito e app
 * Android. Solo admin e super admin, come `SponsorshipBanners::canAccess`.
 * Le stesse impostazioni del pannello (`SponsorshipBannerSettings`).
 */
final class SponsorshipBannerController extends Controller
{
    public function show(SponsorshipBannerSettings $settings): JsonResponse
    {
        $this->ensureAdmin();

        return response()->json(['data' => $this->payload($settings)]);
    }

    /** `web_enabled`, `android_enabled` (sì/no). */
    public function update(Request $request, SaveSponsorshipBanners $save): JsonResponse
    {
        $desk = $this->ensureAdmin();
        $data = $request->validate([
            'web_enabled' => ['required', 'boolean'],
            'android_enabled' => ['required', 'boolean'],
        ]);
        $settings = $save(['web_enabled' => (bool) $data['web_enabled'], 'android_enabled' => (bool) $data['android_enabled']], $desk->user);

        return response()->json(['data' => $this->payload($settings), 'message' => __('sponsorships.banners.saved')]);
    }

    /** @return array<string, mixed> */
    private function payload(SponsorshipBannerSettings $settings): array
    {
        return [
            'web_enabled' => (bool) $settings->web_enabled,
            'android_enabled' => (bool) $settings->android_enabled,
            'lead' => __('sponsorships.banners.lead'),
            'rules' => __('sponsorships.banners.rules'),
            'switch_off' => __('sponsorships.banners.switch_off'),
        ];
    }

    private function ensureAdmin(): EditorialDesk
    {
        $desk = EditorialDesk::require();
        if (! $desk->isAdmin()) {
            throw new ApiException(ApiErrorCode::Forbidden);
        }

        return $desk;
    }
}
