<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Management;

use App\Actions\Account\RespondToCheckinStaffInvitation;
use App\Actions\RespondToVenueInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Management\InvitationResponseRequest;
use App\Models\CheckinStaffInvitation;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use App\Services\Management\PendingInvitations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «I tuoi inviti» (W-CO-07, W-TK-15): gli inviti in un locale e al
 * personale d'ingresso che aspettano una risposta, e la risposta.
 *
 * Sul sito l'invito si accetta da un collegamento firmato, aperto con
 * l'account invitato: la firma prova la casella, la sessione l'account.
 * Nell'app il collegamento non c'è, e le due prove diventano l'accesso con
 * il token **e** l'indirizzo confermato (le rotte stanno dietro
 * `RequireConfirmedAccount`), più l'invito ancora valido per questo account.
 * Un invito di qualcun altro risponde 404, come uno che non esiste.
 */
final class InvitationController extends Controller
{
    /**
     * Inviti ricevuti.
     *
     * `venues[]` `{venue{id, slug, name, city}, role, role_label, sent,
     * invited_at, expires_at}`; `checkin[]` `{id, occurrence{id, title,
     * starts_at, venue}, sent, invited_at, expires_at}`. `sent` va rimandato
     * con la risposta.
     */
    public function index(Request $request, PendingInvitations $invitations): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json(['data' => $invitations->payload($user)]);
    }

    /**
     * Accetta o rifiuta l'invito in un locale.
     *
     * Accettando la persona entra nel locale (e nel pannello) con il ruolo
     * offerto; `subject` è allora `{type: venue, id}`. 404 se l'invito non
     * c'è più, è scaduto, è stato reinviato (`sent` diverso) o è di un altro
     * account.
     */
    public function respondVenue(InvitationResponseRequest $request, int $venue, RespondToVenueInvitation $responses, PendingInvitations $invitations): JsonResponse
    {
        $user = $request->invitee();
        $target = $invitations->venues($user)->whereKey($venue)->first();
        abort_unless($target instanceof Venue && $responses->pending($target, $user, $request->sent()) !== null, 404);

        if (! $request->acceptsInvitation()) {
            $responses->decline($target, $user, $request->sent());

            return response()->json(['data' => ['accepted' => false, 'message' => __('manage.invitation.declined', ['venue' => $target->name]), 'subject' => null]]);
        }

        abort_unless($responses->accept($target, $user, $request->sent(), via: 'app'), 404);

        return response()->json(['data' => [
            'accepted' => true,
            'message' => __('manage.invitation.accepted', ['venue' => $target->name]),
            'subject' => ['type' => 'venue', 'id' => (int) $target->getKey()],
        ]]);
    }

    /**
     * Accetta o rifiuta l'invito al personale d'ingresso di una data.
     *
     * Accettando la persona può controllare i biglietti di quella data;
     * `occurrence_id` è la data. 404 se l'invito non c'è più, è scaduto, è
     * stato reinviato, la data è finita o l'invito è per un altro indirizzo.
     */
    public function respondCheckin(InvitationResponseRequest $request, int $invitation, RespondToCheckinStaffInvitation $responses): JsonResponse
    {
        $user = $request->invitee();
        $found = $responses->pendingFor($user)->with('occurrence')->whereKey($invitation)->first();
        abort_unless(
            $found instanceof CheckinStaffInvitation
            && $found->invited_at->getTimestamp() === $request->sent()
            && $found->occurrence instanceof EventOccurrence,
            404,
        );
        $date = $found->occurrence;

        $accepted = $responses->respond($found, $date, $user, $request->acceptsInvitation());

        if (! $request->acceptsInvitation()) {
            return response()->json(['data' => ['accepted' => false, 'message' => __('decision.staff_invitation.declined'), 'occurrence_id' => null]]);
        }

        abort_unless($accepted, 404);

        return response()->json(['data' => [
            'accepted' => true,
            'message' => __('decision.staff_invitation.accepted'),
            'occurrence_id' => (int) $date->getKey(),
        ]]);
    }
}
