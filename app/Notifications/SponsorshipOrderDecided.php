<?php

declare(strict_types=1);

namespace App\Notifications;

use App\DTOs\NotificationMessage;
use App\Enums\NotificationType;
use App\Enums\SponsorshipOrderStatus;
use App\Models\Organizer;
use App\Models\SponsorshipOrder;
use App\Models\User;
use App\Notifications\Concerns\AlsoInTheApp;
use App\Services\Management\ManagementNoticeTarget;
use Filament\Facades\Filament;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * «Il tuo ordine e' stato approvato / rifiutato», a chi ha pagato.
 *
 * Una classe per i due esiti, per la stessa ragione di `VenueModerated`: le
 * due email hanno la stessa forma e devono restare allineate. Il rifiuto dice
 * il motivo e che il rimborso e' partito: una decisione senza spiegazione
 * genera una risposta a cui qualcuno dovra' rispondere.
 *
 * Nell'app (Q3) arriva anche nella casella e in push, e porta alla pagina
 * delle sponsorizzazioni del locale o dell'organizzazione, con l'ordine.
 */
class SponsorshipOrderDecided extends Notification
{
    use AlsoInTheApp;

    public function __construct(private readonly SponsorshipOrder $order) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->channelsWithApp($notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $approved = $this->order->status === SponsorshipOrderStatus::Approved;
        $vars = $this->vars();
        $group = $this->group();
        $line = $this->line();

        $message = (new MailMessage)
            ->subject(__($group.'_subject', $vars))
            ->greeting(__('notifications.common.greeting', ['name' => $notifiable->name]))
            ->line(__($line, $vars));

        if ($approved) {
            $sponsor = $this->order->sponsor();
            $panel = $sponsor instanceof Organizer ? 'organizer' : 'venue';

            return $message
                ->action(__('notifications.actions.open_panel'), Filament::getPanel($panel)->getUrl($sponsor))
                ->line(__($group.'_why'));
        }

        $reason = $this->reason();
        if ($reason !== '') {
            $message->line(__('notifications.common.reason', ['reason' => $reason]));
        }

        return $message->line(__($group.'_why'));
    }

    protected function appMessage(User $user): NotificationMessage
    {
        $vars = $this->vars();
        $reason = $this->order->status === SponsorshipOrderStatus::Approved ? '' : $this->reason();
        $sponsor = $this->order->sponsor();
        $panel = Filament::getPanel($sponsor instanceof Organizer ? 'organizer' : 'venue');

        return new NotificationMessage(
            type: NotificationType::SponsorshipOrderDecided,
            subject: __($this->group().'_subject', $vars),
            heading: __($this->group().'_subject', $vars),
            lines: array_values(array_filter([__($this->line(), $vars), $reason === '' ? null : __('notifications.common.reason', ['reason' => $reason])])),
            actionLabel: __('notifications.actions.open_panel'),
            url: $panel->getUrl($sponsor) ?? url('/'),
        );
    }

    protected function appTarget(User $user): ?array
    {
        return app(ManagementNoticeTarget::class)->subject($user, $this->order->sponsor(), ManagementNoticeTarget::AREA_SPONSORSHIPS, (int) $this->order->getKey());
    }

    /** @return array<string, string|int> */
    private function vars(): array
    {
        $grant = $this->order->grant;
        $timezone = $this->order->sponsor()->city->timezone;

        return [
            'sponsor' => $this->order->sponsorName(),
            'placement' => $this->order->placement->label(),
            'months' => $this->order->months,
            'amount' => $this->order->formattedAmount(),
            'product' => (string) config('app.name'),
            'from' => $grant?->starts_at->setTimezone($timezone)->format('d/m/Y H:i') ?? '',
            'until' => $grant?->ends_at->setTimezone($timezone)->format('d/m/Y H:i') ?? '',
        ];
    }

    private function group(): string
    {
        return $this->order->status === SponsorshipOrderStatus::Approved ? 'promotions.orders.mail.approved' : 'promotions.orders.mail.rejected';
    }

    /* Una richiesta senza pagamento online non ha niente da rimborsare:
       la riga del rifiuto non deve promettere un rimborso. */
    private function line(): string
    {
        return $this->order->status !== SponsorshipOrderStatus::Approved && $this->order->payment_intent_id === null
            ? 'promotions.orders.mail.rejected_request_line'
            : $this->group().'_line';
    }

    /* Solo la prima riga, il motivo scritto dalla redazione: sotto ci sono
       le note interne (esito del rimborso, errori di Stripe, incassi
       inattesi) che a chi ha pagato non servono. */
    private function reason(): string
    {
        return trim(strtok((string) $this->order->decision_note, "\n") ?: '');
    }
}
