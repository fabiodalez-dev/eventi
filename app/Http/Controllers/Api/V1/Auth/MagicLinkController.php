<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\MagicLinkRequest;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use App\Services\Account\MobileMagicLink;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/** One-use mobile login bound to the requesting installation through S256 PKCE. */
final class MagicLinkController extends Controller
{
    public function __invoke(MagicLinkRequest $request, MobileMagicLink $links): JsonResponse
    {
        $user = User::query()->where('email', (string) $request->validated('email'))->first();

        if ($user instanceof User) {
            $user->notify(new MagicLoginLink($links->issue($user, (string) $request->validated('code_challenge'))));
        }

        return ApiResponse::item(['message' => __('account.api.magic_link_sent')]);
    }
}
