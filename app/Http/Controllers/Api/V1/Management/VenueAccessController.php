<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Management;

use App\Actions\LeaveVenueAction;
use App\Enums\ApiErrorCode;
use App\Enums\VenueRole;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Management\VenueAccessRequest;
use App\Services\Management\ManagementSubjects;
use App\Support\Api\ApiDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * «Il tuo accesso a questo locale» (W-CO-06): ruolo, da quando, e l'uscita
 * da soli. L'unico limite è quello del pannello: l'ultimo referente non esce,
 * lo chiede alla redazione (`LeaveVenueAction`).
 */
final class VenueAccessController extends Controller
{
    /**
     * Il proprio accesso al locale.
     *
     * `role` è `owner` o `editor`; `member_since` la data dell'accettazione
     * (nel fuso della città del locale), nulla per un'appartenenza senza
     * data; `can_leave` è falso solo per l'ultimo referente.
     */
    public function show(VenueAccessRequest $request, LeaveVenueAction $leave): JsonResponse
    {
        $venue = $request->venue();
        $user = $request->member();
        $owner = $user->isOwnerOfVenue((int) $venue->getKey());
        $role = $owner ? VenueRole::Owner : VenueRole::Editor;
        $accepted = $venue->members()->whereKey($user->getKey())->first()?->getRelationValue('pivot')?->getAttribute('accepted_at');
        $lastOwner = $leave->isLastOwner($venue, $user);
        $timezone = (string) ($venue->city->timezone ?? config('app.timezone'));

        return response()->json(['data' => [
            'venue' => ['id' => (int) $venue->getKey(), 'name' => (string) $venue->name],
            'role' => $role->value,
            'role_label' => $role->label(),
            'member_since' => $accepted === null ? null : ApiDate::instant(CarbonImmutable::parse((string) $accepted), $timezone),
            'is_last_owner' => $lastOwner,
            'can_leave' => ! $lastOwner,
            'can_manage_collaborators' => $user->can('manageCollaborators', $venue),
        ]]);
    }

    /**
     * Esci da questo locale.
     *
     * Perdi subito l'accesso; gli eventi inseriti restano al locale. 409
     * `CONFLICT` per l'ultimo referente. `remaining` dice quanti locali e
     * organizzazioni restano da gestire, per sapere dove tornare.
     */
    public function leave(VenueAccessRequest $request, LeaveVenueAction $leave, ManagementSubjects $subjects): JsonResponse
    {
        $venue = $request->venue();
        $user = $request->member();

        if (! $leave->execute($venue, $user)) {
            throw new ApiException(ApiErrorCode::Conflict, __('manage.access.last_owner'));
        }

        $user->refresh();
        $counts = $subjects->counts($user);

        return response()->json(['data' => [
            'message' => __('manage.access.left', ['venue' => $venue->name]),
            'remaining' => $counts['venues'] + $counts['organizers'],
        ]]);
    }
}
