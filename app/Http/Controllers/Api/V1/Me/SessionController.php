<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Me;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\InteractsWithMe;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\SessionResource;
use App\Models\Device;
use App\Support\Api\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

final class SessionController extends Controller
{
    use InteractsWithMe;

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $current = $user->currentAccessToken();
        $currentId = (int) $current->getKey();

        return ApiResponse::collection(
            $user->tokens()
                ->latest('id')
                ->get()
                ->map(fn (PersonalAccessToken $token): array => SessionResource::toArray($token, (string) $user->timezone, $currentId))
                ->all(),
        );
    }

    public function destroy(Request $request, int $session): JsonResponse
    {
        $user = $this->user($request);
        $token = $user->tokens()->whereKey($session)->first();

        if (! $token instanceof PersonalAccessToken) {
            throw new ApiException(ApiErrorCode::NotFound);
        }

        $deviceId = $token->getAttribute('device_id');
        $token->delete();

        if (is_numeric($deviceId)) {
            Device::query()
                ->whereKey((int) $deviceId)
                ->where('user_id', $user->getKey())
                ->update(['revoked_at' => CarbonImmutable::now()]);
        }

        return ApiResponse::item(['message' => __('account.api.session_revoked')]);
    }
}
