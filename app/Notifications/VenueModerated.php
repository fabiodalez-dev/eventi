<?php

declare(strict_types=1);

namespace App\Notifications;

use App\DTOs\NotificationMessage;
use App\Enums\NotificationType;
use App\Enums\VenueStatus;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\Concerns\AlsoInTheApp;
use App\Services\Management\ManagementNoticeTarget;
use Filament\Facades\Filament;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * «Il tuo locale è stato approvato / rifiutato / sospeso».
 *
 * **Perché una sola classe per tre esiti.** Le tre email dicono cose diverse
 * ma hanno la stessa forma — chi, quale locale, cosa cambia adesso — e
 * tenerle insieme impedisce che una si evolva e le altre restino indietro.
 * Il caso da cui guardarsi non e' la duplicazione del codice: e' che fra sei
 * mesi l'email di approvazione spieghi come entrare nel pannello e quella di
 * sospensione mandi ancora a una pagina che non esiste piu'.
 *
 * **Il motivo si dice, quando c'è.** Rifiutare o sospendere senza dire perché
 * produce una risposta che qualcuno dovrà leggere e a cui dovrà rispondere:
 * la frase in piu' qui costa meno di quel giro.
 *
 * I testi passano tutti da `notifications.venue_*`, quindi si riscrivono dal
 * pannello senza toccare questo file.
 *
 * **Nell'app (Q3)** arriva anche in casella e in push. Porta alla pagina del
 * locale nella gestione solo se la persona ci entra ancora: un locale
 * rifiutato o sospeso non si apre, come il pannello, e l'avviso resta da
 * leggere senza un bersaglio.
 */
class VenueModerated extends Notification
{
    use AlsoInTheApp;

    public function __construct(
        private readonly Venue $venue,
        private readonly VenueStatus $esito,
        private readonly ?string $motivo = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->channelsWithApp($notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $gruppo = 'notifications.'.$this->gruppo();
        $prodotto = (string) config('app.name');

        $messaggio = (new MailMessage)
            ->subject(__($gruppo.'.subject', ['venue' => $this->venue->name, 'product' => $prodotto]))
            ->greeting(__('notifications.common.greeting', ['name' => $notifiable->name]))
            ->line(__($gruppo.'.line', ['venue' => $this->venue->name, 'product' => $prodotto]));

        if ($this->motivo !== null && trim($this->motivo) !== '') {
            $messaggio->line(__('notifications.common.reason', ['reason' => trim($this->motivo)]));
        }

        /*
         * Il pulsante porta al pannello solo quando c'e' qualcosa da farci.
         * Su un locale rifiutato o sospeso il referente non entra piu' — lo
         * impedisce `canAccessPanel` — e offrirgli un collegamento che lo
         * respinge e' peggio che non offrirne nessuno.
         */
        if ($this->esito === VenueStatus::Approved) {
            $messaggio->action(
                __('notifications.actions.open_panel'),
                Filament::getPanel('venue')->getUrl(),
            );
        }

        return $messaggio->line(__($gruppo.'.why'));
    }

    protected function appMessage(User $user): NotificationMessage
    {
        $gruppo = 'notifications.'.$this->gruppo();
        $vars = ['venue' => $this->venue->name, 'product' => (string) config('app.name')];
        $motivo = trim((string) $this->motivo);

        return new NotificationMessage(
            type: NotificationType::VenueModerated,
            subject: __($gruppo.'.subject', $vars),
            heading: __($gruppo.'.subject', $vars),
            lines: array_values(array_filter([
                __($gruppo.'.line', $vars),
                $motivo === '' ? null : __('notifications.common.reason', ['reason' => $motivo]),
            ])),
            actionLabel: __('notifications.actions.open_panel'),
            /* Il pannello solo quando ci si entra, come il pulsante dell'email: un locale rifiutato o sospeso non ha una pagina da offrire. */
            url: $this->esito === VenueStatus::Approved ? Filament::getPanel('venue')->getUrl() ?? url('/') : url('/'),
            venueId: (int) $this->venue->getKey(),
        );
    }

    protected function appTarget(User $user): ?array
    {
        return $this->esito === VenueStatus::Approved
            ? app(ManagementNoticeTarget::class)->subject($user, $this->venue, ManagementNoticeTarget::AREA_HOME)
            : null;
    }

    private function gruppo(): string
    {
        return match ($this->esito) {
            VenueStatus::Approved => 'venue_approved',
            VenueStatus::Rejected => 'venue_rejected',
            VenueStatus::Suspended => 'venue_suspended',
            /* Bozza e attesa non sono decisioni: non c'e' niente da
               comunicare, e mandare un'email per dire che non e' successo
               nulla insegna a ignorare le successive. */
            VenueStatus::Draft, VenueStatus::Pending => 'venue_approved',
        };
    }
}
