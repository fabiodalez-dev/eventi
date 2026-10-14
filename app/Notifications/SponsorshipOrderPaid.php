<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Admin\Resources\SponsorshipOrders\SponsorshipOrderResource;
use App\Models\SponsorshipOrder;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * «C'e' un ordine pagato da approvare», alla redazione.
 *
 * Il pagamento non apre nulla da solo: senza questa email un ordine pagato
 * resterebbe fermo finche' qualcuno non apre la pagina giusta per caso.
 */
class SponsorshipOrderPaid extends Notification
{
    public function __construct(private readonly SponsorshipOrder $order) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $vars = [
            'sponsor' => $this->order->sponsorName(),
            'placement' => $this->order->placement->label(),
            'months' => $this->order->months,
            'amount' => $this->order->formattedAmount(),
            'product' => (string) config('app.name'),
        ];

        return (new MailMessage)
            ->subject(__('promotions.orders.mail.paid_subject', $vars))
            ->greeting(__('notifications.common.greeting', ['name' => $notifiable->name]))
            ->line(__('promotions.orders.mail.paid_line', $vars))
            ->action(__('promotions.orders.mail.paid_action'), SponsorshipOrderResource::getUrl('index', panel: 'admin'))
            ->line(__('promotions.orders.mail.paid_why'));
    }
}
