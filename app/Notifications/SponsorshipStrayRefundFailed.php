<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Admin\Resources\SponsorshipOrders\SponsorshipOrderResource;
use App\Models\SponsorshipOrder;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * «Un incasso inatteso non si e' rimborsato da solo», alla redazione.
 *
 * Un pagamento arrivato su un ordine che non lo aspettava piu' si rimborsa
 * in automatico; quando Stripe rifiuta, i soldi restano incassati e la nota
 * sull'ordine sta in una colonna nascosta. Senza questa email nessuno se ne
 * accorgerebbe finche' il committente non chiede indietro i suoi soldi.
 */
class SponsorshipStrayRefundFailed extends Notification
{
    public function __construct(
        private readonly SponsorshipOrder $order,
        private readonly string $paymentIntentId,
        private readonly string $error,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $vars = [
            'sponsor' => $this->order->sponsorName(),
            'intent' => $this->paymentIntentId,
            'error' => $this->error,
            'product' => (string) config('app.name'),
        ];

        return (new MailMessage)
            ->subject(__('promotions.orders.mail.stray_refund_failed_subject', $vars))
            ->greeting(__('notifications.common.greeting', ['name' => $notifiable->name]))
            ->line(__('promotions.orders.mail.stray_refund_failed_line', $vars))
            ->action(__('promotions.orders.mail.paid_action'), SponsorshipOrderResource::getUrl('index', panel: 'admin'))
            ->line(__('promotions.orders.mail.stray_refund_failed_why', $vars));
    }
}
