<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Models\CheckinStaffInvitation;
use App\Models\EventOccurrence;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * «Accetta l'invito» al personale di ingresso di una data (D67).
 *
 * Come l'invito nei locali (D64): la GET mostra la domanda, il POST con CSRF
 * decide, così gli scanner di posta non accettano al posto della persona. La
 * firma dice che il collegamento è arrivato nella casella, la sessione che
 * risponde il titolare dell'account con quell'indirizzo.
 */
final class CheckinStaffInvitationController extends Controller
{
    public function show(Request $request, int $invitation, int $sent): Response|RedirectResponse
    {
        $check = $this->check($request, $invitation, $sent);

        if (! $check instanceof CheckinStaffInvitation) {
            return $check;
        }

        return response()->view('account.checkin-staff-invitation', [
            'date' => $check->occurrence,
            'action' => $request->fullUrl(),
            'error' => null,
            'meta' => $this->meta(),
        ]);
    }

    public function respond(Request $request, int $invitation, int $sent): Response|RedirectResponse
    {
        $decision = $request->validate(['decision' => ['required', 'in:accept,decline']])['decision'];
        $check = $this->check($request, $invitation, $sent);

        if (! $check instanceof CheckinStaffInvitation) {
            return $check;
        }

        /** @var User $user */
        $user = $request->user();
        /** @var EventOccurrence $date */
        $date = $check->occurrence;

        $accepted = DB::transaction(function () use ($check, $user, $date, $decision): bool {
            $locked = CheckinStaffInvitation::query()->whereKey($check->getKey())->lockForUpdate()->first();
            if (! $locked instanceof CheckinStaffInvitation) {
                return false;
            }
            $locked->delete();
            if ($decision === 'decline') {
                return false;
            }
            $date->checkinStaff()->syncWithoutDetaching([$user->id]);
            activity('ticketing')->causedBy($user)->performedOn($date)->withProperties(['staff_id' => $user->id])->log('staff_assigned');

            return true;
        });

        if ($decision === 'decline') {
            return redirect()->route('account.profile')->with('status', __('decision.staff_invitation.declined'));
        }

        return $accepted
            ? redirect()->route('ticketing.manage.scanner', $date)->with('status', __('decision.staff_invitation.accepted'))
            : $this->error(__('decision.staff_invitation.gone'));
    }

    /**
     * Le verifiche nell'ordine in cui la persona può rimediare: un
     * collegamento scaduto si fa reinviare, un account sbagliato si cambia,
     * un invito ritirato o una data finita non si recuperano.
     */
    private function check(Request $request, int $invitationId, int $sent): CheckinStaffInvitation|Response|RedirectResponse
    {
        if (! URL::hasCorrectSignature($request)) {
            return $this->error(__('decision.staff_invitation.invalid'));
        }

        if (! URL::signatureHasNotExpired($request)) {
            return $this->error(__('decision.staff_invitation.expired'));
        }

        /** @var User $user */
        $user = $request->user();
        $invitation = CheckinStaffInvitation::query()->with('occurrence.event')->find($invitationId);

        // Un invito reinviato ha una data nuova: il collegamento vecchio non vale più.
        if (! $invitation instanceof CheckinStaffInvitation || $invitation->invited_at->getTimestamp() !== $sent) {
            return $this->error(__('decision.staff_invitation.gone'));
        }

        $date = $invitation->occurrence;
        if (! $date instanceof EventOccurrence || ($date->effective_ends_at ?? $date->ends_at ?? $date->starts_at)->isPast()) {
            return $this->error(__('decision.staff_invitation.gone'));
        }

        if (mb_strtolower((string) $user->email) !== $invitation->email) {
            if ($date->checkinStaff()->whereKey($user->id)->exists()) {
                return redirect()->route('ticketing.manage.scanner', $date);
            }
            [$local, $domain] = array_pad(explode('@', $invitation->email, 2), 2, '');

            return $this->error(__('decision.staff_invitation.wrong_account', ['email' => Str::mask($local, '*', 1).'@'.$domain]));
        }

        return $invitation;
    }

    private function error(string $message): Response
    {
        return response()->view('account.checkin-staff-invitation', [
            'date' => null,
            'action' => null,
            'error' => $message,
            'meta' => $this->meta(),
        ], 403);
    }

    private function meta(): PageMeta
    {
        return new PageMeta(
            title: __('decision.staff_invitation.page_title'),
            heading: __('decision.staff_invitation.page_title'),
            indexable: false,
        );
    }
}
