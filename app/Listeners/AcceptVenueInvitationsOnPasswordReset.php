<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\RespondToVenueInvitation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\PasswordReset;

/**
 * La password scelta dal collegamento dell'invito vale come accettarlo (D64),
 * ma solo per l'invito che ha creato l'account (`accepts_on_password`): è lì
 * che la persona dimostra la casella, e il messaggio le diceva proprio che
 * scegliere la password significava entrare nel locale.
 *
 * Dopo la password il sito manda alla pagina di accesso: chi ha appena
 * accettato, una volta entrato, arriva al pannello del locale invece che al
 * feed.
 */
final class AcceptVenueInvitationsOnPasswordReset
{
    public function __construct(private readonly RespondToVenueInvitation $invitations) {}

    public function handle(PasswordReset $event): void
    {
        if (! $event->user instanceof User || $this->invitations->acceptOnPassword($event->user) === 0) {
            return;
        }

        $request = request();
        $venue = $event->user->venues()->latest('venue_user.accepted_at')->first();

        if ($venue !== null && $request->hasSession()) {
            $request->session()->put('url.intended', Filament::getPanel('venue')->getUrl($venue));
        }
    }
}
