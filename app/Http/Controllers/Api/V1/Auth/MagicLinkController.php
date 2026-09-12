<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\MagicLinkRequest;
use App\Models\MobileAuthChallenge;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use App\Support\Api\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/** One-use mobile login bound to the requesting installation through S256 PKCE. */
final class MagicLinkController extends Controller
{
    public function __invoke(MagicLinkRequest $request): JsonResponse
    {
        $user = User::query()->where('email', (string) $request->validated('email'))->first();

        if ($user instanceof User) {
            $rawToken = Str::random(64);
            $minutes = config()->integer('account.magic_link_minutes');

            /* Un secondo invio rende inutilizzabile il precedente: oltre a
               limitare la tabella, elimina l'ambiguita' di avere piu' link
               validi per lo stesso account nello stesso momento. */
            MobileAuthChallenge::query()->where('user_id', $user->getKey())->delete();

            MobileAuthChallenge::query()->create([
                'user_id' => $user->getKey(),
                'password_fingerprint' => MagicLoginLink::fingerprint($user),
                'code_challenge' => $request->validated('code_challenge'),
                'token_hash' => hash('sha256', $rawToken),
                'expires_at' => CarbonImmutable::now()->addMinutes($minutes),
            ]);

            $url = url('/app/auth/magic?token='.rawurlencode($rawToken));

            $user->notify(new MagicLoginLink($url));
        }

        return ApiResponse::item(['message' => __('account.api.magic_link_sent')]);
    }
}
