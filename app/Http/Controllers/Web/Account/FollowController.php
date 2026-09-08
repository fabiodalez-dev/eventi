<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\Actions\Account\FollowSubject;
use App\Actions\Account\UnfollowSubject;
use App\Enums\FollowableType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithAccount;
use App\Http\Requests\Web\Account\StoreFollowRequest;
use App\Models\Organizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * «Segui» dal sito (§15.3, §15.7).
 *
 * Locali, tag e categorie alimentano il feed; `event` è l'altro caso, quello
 * di una serie ricorrente: seguirla significa ritrovarsi ogni nuova data nei
 * salvataggi, e quindi nei promemoria.
 */
final class FollowController extends Controller
{
    use InteractsWithAccount;

    public function store(StoreFollowRequest $request, FollowSubject $follow): RedirectResponse|JsonResponse
    {
        $type = $request->followableType();
        $id = (int) $request->validated('id');

        /*
         * `follows` è una colonna polimorfa e non ha una chiave esterna: senza
         * questo controllo si potrebbe seguire un locale mai esistito, che poi
         * comparirebbe nell'elenco come una riga senza nome.
         */
        abort_unless($type->modelClass()::query()->whereKey($id)->exists(), 404);
        abort_if($type === FollowableType::Organizer && ! Organizer::query()->whereKey($id)->where('is_active', true)->exists(), 404);

        $follow($this->accountUser($request), $type, $id, $request->boolean('notify', $type !== FollowableType::Organizer));

        return $this->respond($request, __('account.follow.stored'));
    }

    public function destroy(Request $request, string $type, int $id, UnfollowSubject $unfollow): RedirectResponse|JsonResponse
    {
        $followable = FollowableType::tryFrom($type);

        abort_if($followable === null, 404);

        $unfollow($this->accountUser($request), $followable, $id);

        return $this->respond($request, __('account.follow.removed'));
    }

    private function respond(Request $request, string $status): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $status]);
        }

        return back()->with('status', $status);
    }
}
