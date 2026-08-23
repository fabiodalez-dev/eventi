<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Me;

use App\Actions\Account\FollowSubject;
use App\Actions\Account\UnfollowSubject;
use App\Enums\ApiErrorCode;
use App\Enums\FollowableType;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\InteractsWithMe;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Me\MeQueryRequest;
use App\Http\Requests\Api\V1\Me\StoreFollowRequest;
use App\Http\Resources\V1\FollowResource;
use App\Models\Follow;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET/POST/DELETE /v1/me/follows` (§15.8).
 *
 * Seguire alimenta il feed e i digest, **non** i promemoria (§15.3): l'unica
 * eccezione è `type=event`, che significa «salvami ogni data nuova» e infatti
 * produce salvataggi.
 */
final class FollowController extends Controller
{
    use InteractsWithMe;

    public function index(MeQueryRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $timezone = (string) $user->timezone;

        $paginator = $user->follows()
            ->with('followable')
            ->orderByDesc('id')
            ->cursorPaginate($request->limit(), cursor: $request->cursor())
            ->withQueryString();

        return ApiResponse::page(
            $paginator,
            static fn (Follow $follow): array => FollowResource::toArray($follow, $timezone),
        );
    }

    public function store(StoreFollowRequest $request, FollowSubject $follow): JsonResponse
    {
        $user = $this->user($request);
        $type = $request->followableType();
        $id = (int) $request->validated('id');

        /*
         * Il soggetto deve esistere: `follows` non ha una chiave esterna
         * (la colonna è polimorfa) e senza questo controllo si potrebbe
         * seguire un locale mai esistito, che poi comparirebbe nel feed come
         * una riga vuota.
         */
        if (! $type->modelClass()::query()->whereKey($id)->exists()) {
            throw new ApiException(ApiErrorCode::NotFound);
        }

        $row = $follow($user, $type, $id, $request->boolean('notify', true));
        $row->load('followable');

        return ApiResponse::item(
            FollowResource::toArray($row, (string) $user->timezone),
            status: 201,
        );
    }

    public function destroy(Request $request, string $type, int $id, UnfollowSubject $unfollow): JsonResponse
    {
        $followable = FollowableType::tryFrom($type);

        if ($followable === null) {
            throw new ApiException(ApiErrorCode::NotFound);
        }

        if (! $unfollow($this->user($request), $followable, $id)) {
            throw new ApiException(ApiErrorCode::NotFound);
        }

        return ApiResponse::item(['message' => __('account.api.unfollowed')]);
    }
}
