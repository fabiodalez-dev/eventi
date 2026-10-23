<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\DTOs\PageMeta;
use App\Enums\AdmissionStatus;
use App\Http\Controllers\Controller;
use App\Models\AdmissionTicket;
use App\Models\User;
use App\Services\Ticketing\TicketSharing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `/biglietto/{ticket}?expires=…&signature=…`: un solo biglietto, per chi lo
 * ha ricevuto dal prenotante. La firma è la chiave; il QR compare solo se il
 * biglietto è ancora valido, così un biglietto annullato non entra da qui.
 */
final class SharedTicketController extends Controller
{
    public function show(Request $request, AdmissionTicket $ticket): Response
    {
        abort_unless($request->hasValidSignature(), 403);
        $ticket->load('booking.occurrence.event.city', 'booking.occurrence.venue');

        return response()->view('ticketing.shared', [
            'ticket' => $ticket,
            'valid' => $ticket->displayStatus() === AdmissionStatus::Valid,
            'meta' => new PageMeta(title: __('ticketing.share.page_title'), heading: __('ticketing.share.page_title'), indexable: false),
        ])->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }

    public function send(Request $request, AdmissionTicket $ticket, TicketSharing $sharing): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $data = $request->validate([
            'email' => ['required', 'email:filter', 'max:255'],
            'consent' => ['accepted'],
        ], [], ['email' => __('ticketing.email'), 'consent' => __('ticketing.share.consent')]);
        $sharing->email($ticket, $user, (string) $data['email']);

        return $request->is('api/*')
            ? response()->json(['data' => ['message' => __('ticketing.share.sent')]])
            : back()->with('status', __('ticketing.share.sent'));
    }
}
