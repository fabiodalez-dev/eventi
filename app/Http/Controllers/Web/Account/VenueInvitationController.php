<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\Actions\RespondToVenueInvitation;
use App\DTOs\PageMeta;
use App\Enums\VenueRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Venue;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * «Accetta l'invito» (D64): il collegamento che arriva a chi è stato invitato
 * in un locale con un account che aveva già.
 *
 * La GET mostra soltanto la domanda — quale locale, con quale ruolo — e due
 * pulsanti; decide il POST, con CSRF, verso lo stesso indirizzo firmato. Così
 * gli scanner di posta, che aprono ogni collegamento prima della persona, non
 * accettano niente al posto suo.
 *
 * La firma non la controlla il middleware `signed`, che risponderebbe con un
 * 403 muto: qui si distingue il collegamento scaduto (chiedi di reinviarlo)
 * da quello alterato, e l'account sbagliato da un invito che non c'è più.
 */
final class VenueInvitationController extends Controller
{
    public function show(Request $request, int $venue, int $user, int $invitation, RespondToVenueInvitation $invitations): Response|RedirectResponse
    {
        $check = $this->check($request, $venue, $user, $invitation, $invitations);

        if ($check instanceof Response || $check instanceof RedirectResponse) {
            return $check;
        }

        [$locale, $ruolo] = $check;

        return response()->view('account.venue-invitation', [
            'venue' => $locale,
            'role' => $ruolo,
            'action' => $request->fullUrl(),
            'error' => null,
            'meta' => $this->meta(),
        ]);
    }

    public function respond(Request $request, int $venue, int $user, int $invitation, RespondToVenueInvitation $invitations): Response|RedirectResponse
    {
        $decision = $request->validate(['decision' => ['required', 'in:accept,decline']])['decision'];
        $check = $this->check($request, $venue, $user, $invitation, $invitations);

        if ($check instanceof Response || $check instanceof RedirectResponse) {
            return $check;
        }

        [$locale] = $check;
        /** @var User $utente */
        $utente = $request->user();

        if ($decision === 'decline') {
            $invitations->decline($locale, $utente, $invitation);

            return redirect()->route('account.profile')->with('status', __('manage.invitation.declined', ['venue' => $locale->name]));
        }

        if (! $invitations->accept($locale, $utente, $invitation)) {
            return $this->error(__('manage.invitation.gone'));
        }

        return redirect()->to(Filament::getPanel('venue')->getUrl($locale) ?? url('/'));
    }

    /**
     * Le quattro verifiche, nell'ordine in cui la persona può rimediare: un
     * collegamento scaduto si fa reinviare, un account sbagliato si cambia,
     * un invito ritirato o sostituito non si recupera.
     *
     * @return array{0: Venue, 1: VenueRole}|Response|RedirectResponse
     */
    private function check(Request $request, int $venueId, int $userId, int $invitation, RespondToVenueInvitation $invitations): array|Response|RedirectResponse
    {
        if (! URL::hasCorrectSignature($request)) {
            return $this->error(__('manage.invitation.invalid'));
        }

        if (! URL::signatureHasNotExpired($request)) {
            return $this->error(__('manage.invitation.expired'));
        }

        $utente = $request->user();
        $invitato = User::query()->find($userId);

        if (! $utente instanceof User || ! $invitato instanceof User || ! $utente->is($invitato)) {
            [$locale, $dominio] = array_pad(explode('@', (string) $invitato?->email, 2), 2, '');

            return $this->error(__('manage.invitation.wrong_account', [
                'email' => $invitato instanceof User ? Str::mask($locale, '*', 1).'@'.$dominio : '—',
            ]));
        }

        $venue = Venue::query()->find($venueId);

        if (! $venue instanceof Venue) {
            return $this->error(__('manage.invitation.gone'));
        }

        $pivot = $invitations->pending($venue, $utente, $invitation);

        if ($pivot === null) {
            /* Già accettato (un secondo clic, un altro dispositivo): si entra,
               invece di spiegare un errore che non c'è. */
            if ($utente->isMemberOfVenue((int) $venue->getKey())) {
                return redirect()->to(Filament::getPanel('venue')->getUrl($venue) ?? url('/'));
            }

            return $this->error(__('manage.invitation.gone'));
        }

        return [$venue, VenueRole::from((string) $pivot->getAttribute('role'))];
    }

    private function error(string $message): Response
    {
        return response()->view('account.venue-invitation', [
            'venue' => null,
            'role' => null,
            'action' => null,
            'error' => $message,
            'meta' => $this->meta(),
        ], 403);
    }

    private function meta(): PageMeta
    {
        return new PageMeta(
            title: __('manage.invitation.page_title'),
            heading: __('manage.invitation.page_title'),
            indexable: false,
        );
    }
}
