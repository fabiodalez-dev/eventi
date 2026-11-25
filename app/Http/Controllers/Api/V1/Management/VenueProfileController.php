<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Management;

use App\Actions\PublishVenueNotice;
use App\Actions\UpdateVenueProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Management\VenueNoticeRequest;
use App\Http\Requests\Api\V1\Management\VenueProfileRequest;
use App\Http\Resources\V1\Management\VenueProfileResource;
use App\Models\User;
use App\Support\Api\ApiDate;
use Illuminate\Http\JsonResponse;

/**
 * La scheda del locale e «Avvisa chi ti segue», come la pagina «Il tuo
 * locale» del pannello. Solo il referente.
 */
final class VenueProfileController extends Controller
{
    /** Tutti i campi della scheda, con gli elenchi e i tetti per disegnarne le sezioni. */
    public function show(VenueProfileRequest $request): JsonResponse
    {
        return response()->json(['data' => VenueProfileResource::toArray($request->venue())]);
    }

    /**
     * Salva le chiavi inviate, di solito una sezione alla volta; le altre
     * restano come sono. Risponde con la scheda aggiornata.
     */
    public function update(VenueProfileRequest $request, UpdateVenueProfile $action): JsonResponse
    {
        $venue = $action->handle($request->venue(), $request->profileState());

        return response()->json(['data' => VenueProfileResource::toArray($venue->refresh())]);
    }

    /**
     * Pubblica un avviso a chi segue il locale: uno ogni sette giorni, se no
     * 422 su `body`. Risponde con l'avviso e con quante persone lo ricevono.
     */
    public function notice(VenueNoticeRequest $request, PublishVenueNotice $action): JsonResponse
    {
        $venue = $request->venue();
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $notice = $action->handle($venue, $user, $request->kind(), (string) $request->validated('body'), $request->until());
        $timezone = $venue->city->timezone ?? config()->string('app.timezone');
        $notified = $venue->followers()->where('notify', true)->count();

        return response()->json(['data' => [
            'notice' => [
                'id' => (int) $notice->getKey(),
                'kind' => $notice->kind->value,
                'kind_label' => $notice->kind->label(),
                'body' => (string) $notice->body,
                'until' => ApiDate::day($notice->until),
                'created_at' => ApiDate::instant($notice->created_at, $timezone),
            ],
            'notified' => $notified,
            'message' => (string) __('manage.notices.published', ['count' => $notified]),
        ]], 201);
    }
}
