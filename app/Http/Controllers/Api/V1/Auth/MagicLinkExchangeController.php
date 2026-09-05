<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\MagicLinkExchangeRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\MobileAuthChallenge;
use App\Models\User;
use App\Services\Account\MobileTokenIssuer;
use App\Support\Api\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class MagicLinkExchangeController extends Controller
{
    public function __invoke(MagicLinkExchangeRequest $request, MobileTokenIssuer $tokens): JsonResponse
    {
        $hash = hash('sha256', (string) $request->validated('token'));

        $user = DB::transaction(function () use ($hash): ?User {
            $challenge = MobileAuthChallenge::query()
                ->where('token_hash', $hash)
                ->lockForUpdate()
                ->first();

            if (! $challenge instanceof MobileAuthChallenge
                || $challenge->used_at !== null
                || $challenge->expires_at->isPast()) {
                return null;
            }

            $challenge->forceFill(['used_at' => CarbonImmutable::now()])->save();

            return $challenge->user;
        });

        if (! $user instanceof User) {
            throw new ApiException(ApiErrorCode::InvalidToken);
        }

        $issued = $tokens->issue($user, $request->validated('device_name'));
        $user->forceFill(['last_active_at' => CarbonImmutable::now()])->save();

        return ApiResponse::item([
            ...$issued,
            'token_type' => 'Bearer',
            'user' => UserResource::toArray($user),
            'message' => __('api.auth.logged_in'),
        ]);
    }
}
