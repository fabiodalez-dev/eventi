<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Management;

use App\Actions\UpdateOrganizerProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Management\OrganizerProfileRequest;
use App\Http\Resources\V1\Management\OrganizerProfileResource;
use Illuminate\Http\JsonResponse;

/**
 * Il profilo dell'organizzazione, come la pagina «Profilo» del pannello
 * dell'organizzatore. Solo chi l'ha creata.
 */
final class OrganizerProfileController extends Controller
{
    public function show(OrganizerProfileRequest $request): JsonResponse
    {
        return response()->json(['data' => OrganizerProfileResource::toArray($request->organizer())]);
    }

    /** Salva le chiavi inviate; le altre restano come sono. */
    public function update(OrganizerProfileRequest $request, UpdateOrganizerProfile $action): JsonResponse
    {
        $organizer = $action->handle($request->organizer(), $request->profileState());

        return response()->json(['data' => OrganizerProfileResource::toArray($organizer->refresh())]);
    }
}
