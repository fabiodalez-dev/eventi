<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Editorial\EditorialDeskPayload;
use App\Services\Editorial\EditorialOptions;
use App\Services\Editorial\EditorialUnlocks;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Il banco della redazione: la prima cosa che l'app chiede entrando
 * nell'area «Redazione», e gli elenchi dei moduli. Non chiedono lo sblocco:
 * servono proprio a sapere se la redazione è sbloccata.
 */
final class DeskController extends Controller
{
    /**
     * Chi lavora, dove e con quali permessi.
     *
     * `role` è il ruolo più alto (`moderator`, `admin`, `super_admin`);
     * `city` la città di lavoro (`?city=`, anche non attiva); `cities` tutte
     * le città; `permissions` una chiave per azione, calcolata dalle policy
     * del sito; `badges` i contatori del menu del pannello (`null` dove la
     * voce non si vede); `unlock` lo stato dello sblocco di questo token.
     */
    public function show(Request $request, EditorialDeskPayload $payload, EditorialUnlocks $unlocks): JsonResponse
    {
        return response()->json(['data' => $payload->for(EditorialDesk::require(), $unlocks->find($request))]);
    }

    /** Elenchi chiusi e tetti dei moduli della redazione. */
    public function options(Request $request, EditorialOptions $options): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json(['data' => $options->payload($user)]);
    }
}
