<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Account\ClaimMailbox;
use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\MagicLinkExchangeRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\MobileAuthChallenge;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use App\Services\Account\MobileTokenIssuer;
use App\Support\Api\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class MagicLinkExchangeController extends Controller
{
    public function __invoke(MagicLinkExchangeRequest $request, MobileTokenIssuer $tokens, ClaimMailbox $claim): JsonResponse
    {
        $hash = hash('sha256', (string) $request->validated('token'));

        $result = DB::transaction(function () use ($hash, $request, $tokens, $claim): ?array {
            $challenge = MobileAuthChallenge::query()
                ->where('token_hash', $hash)
                ->lockForUpdate()
                ->first();

            if (! $challenge instanceof MobileAuthChallenge
                || ! is_string($challenge->code_challenge)
                || ! hash_equals($challenge->code_challenge, rtrim(strtr(base64_encode(hash('sha256', (string) $request->validated('code_verifier'), true)), '+/', '-_'), '='))
                || $challenge->used_at !== null
                || $challenge->expires_at->isPast()) {
                return null;
            }

            $user = User::query()->whereKey($challenge->user_id)->lockForUpdate()->first();
            if (! $user instanceof User || ! is_string($challenge->password_fingerprint)
                || ! hash_equals($challenge->password_fingerprint, MagicLoginLink::fingerprint($user))) {
                return null;
            }
            $challenge->forceFill(['used_at' => CarbonImmutable::now()])->save();
            /* Il collegamento prova la casella: password e token nati prima di
               questa prova si azzerano (`ClaimMailbox`). Prima di emettere il
               token nuovo, che così sopravvive. */
            $claim($user);
            $issued = $tokens->issue($user, $request->validated('device_name'));
            $user->forceFill(['last_active_at' => CarbonImmutable::now()])->save();

            return [$user, $issued];
        });

        if ($result === null) {
            throw new ApiException(ApiErrorCode::InvalidToken);
        }

        [$user, $issued] = $result;

        return ApiResponse::item([
            ...$issued,
            'token_type' => 'Bearer',
            'user' => UserResource::toArray($user),
            'message' => __('api.auth.logged_in'),
        ]);
    }
}
