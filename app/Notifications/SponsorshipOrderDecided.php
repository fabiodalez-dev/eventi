<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\SponsorshipOrderStatus;
use App\Models\Organizer;
use App\Models\SponsorshipOrder;
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
 */
class SponsorshipOrderDecided extends Notification
{
    public function __construct(private readonly SponsorshipOrder $order) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $approved = $this->order->status === SponsorshipOrderStatus::Approved;
        $grant = $this->order->grant;
        $timezone = $this->order->sponsor()->city->timezone;
        $vars = [
            'sponsor' => $this->order->sponsorName(),
            'placement' => $this->order->placement->label(),
            'months' => $this->order->months,
            'amount' => $this->order->formattedAmount(),
            'product' => (string) config('app.name'),
            'from' => $grant?->starts_at->setTimezone($timezone)->format('d/m/Y H:i') ?? '',
            'until' => $grant?->ends_at->setTimezone($timezone)->format('d/m/Y H:i') ?? '',
        ];
        $group = $approved ? 'promotions.orders.mail.approved' : 'promotions.orders.mail.rejected';

        $message = (new MailMessage)
            ->subject(__($group.'_subject', $vars))
            ->greeting(__('notifications.common.greeting', ['name' => $notifiable->name]))
            ->line(__($group.'_line', $vars));

        if ($approved) {
            $sponsor = $this->order->sponsor();
            $panel = $sponsor instanceof Organizer ? 'organizer' : 'venue';

            return $message
                ->action(__('notifications.actions.open_panel'), Filament::getPanel($panel)->getUrl($sponsor))
                ->line(__($group.'_why'));
        }

        $reason = trim((string) $this->order->decision_note);
        if ($reason !== '') {
            $message->line(__('notifications.common.reason', ['reason' => $reason]));
        }

        return $message->line(__($group.'_why'));
    }
}
