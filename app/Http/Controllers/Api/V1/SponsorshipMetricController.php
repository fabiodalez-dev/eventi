<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\InteractsWithApi;
use App\Http\Controllers\Controller;
use App\Models\Sponsorship;
use App\Services\Sponsorship\RecordSponsorshipMetric;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class SponsorshipMetricController extends Controller
{
    use InteractsWithApi;

    public function __invoke(Request $request, int $sponsorship, string $metric): JsonResponse
    {
        if (! in_array($metric, ['impressions', 'clicks'], true)) {
            throw new ApiException(ApiErrorCode::NotFound);
        }

        $campaign = Sponsorship::query()
            ->visible()
            ->where('city_id', $this->city()->getKey())
            ->whereKey($sponsorship)
            ->first();

        if (! $campaign instanceof Sponsorship || ! $this->validToken($request, $campaign)) {
            throw new ApiException(ApiErrorCode::InvalidToken);
        }

        $actor = $this->actor($request);
        $dedupe = 'api-sponsor:'.hash('sha256', $actor.'|'.$campaign->getKey().'|'.$metric);

        $request->validate(['click_id' => 'nullable|uuid']);
        if (! ($metric === 'clicks' && $request->filled('click_id')) && ! Cache::add($dedupe, true, now()->addMinutes(15))) {
            return response()->json(status: 204);
        }

        app(RecordSponsorshipMetric::class)->record($campaign, $metric, $request, $request->hasHeader('X-Installation-ID') ? 'android' : 'web');

        return response()->json(status: 204);
    }

    private function validToken(Request $request, Sponsorship $campaign): bool
    {
        $provided = $request->header('X-Metric-Token');

        if (! is_string($provided)) {
            return false;
        }

        foreach ([now('UTC'), now('UTC')->subDay()] as $day) {
            $expected = hash_hmac(
                'sha256',
                $campaign->getKey().'|'.$campaign->placement->value.'|'.$day->format('Y-m-d'),
                (string) config('app.key'),
            );

            if (hash_equals($expected, $provided)) {
                return true;
            }
        }

        return false;
    }

    private function actor(Request $request): string
    {
        $installation = $request->header('X-Installation-ID');

        if (is_string($installation) && preg_match('/^[A-Za-z0-9._:-]{16,64}$/', $installation) === 1) {
            return 'installation:'.$installation;
        }

        $user = $request->user('sanctum');

        return $user === null ? 'ip:'.($request->ip() ?? 'unknown') : 'user:'.$user->getAuthIdentifier();
    }
}
