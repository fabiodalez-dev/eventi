<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Admin\Resources\SponsorshipOrders\SponsorshipOrderResource;
use App\Models\SponsorshipOrder;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * «C'e' una richiesta di sponsorizzazione da valutare», alla redazione.
 *
 * E' la strada senza pagamento online: nessun incasso da riconciliare, ma la
 * richiesta resterebbe ferma lo stesso finche' qualcuno non apre l'elenco
 * ordini. L'email dice chi chiede, cosa, per quanto e su quale evento, e
 * riporta il messaggio di chi chiede, se c'e'.
 */
class SponsorshipOrderRequested extends Notification
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
            'amount' => $this->order->amount_cents > 0 ? $this->order->formattedAmount() : __('promotions.request.price_to_agree'),
            'event' => $this->order->event_id === null ? __('promotions.request.all_events') : (string) $this->order->event->title,
            'product' => (string) config('app.name'),
        ];

        $message = (new MailMessage)
            ->subject(__('promotions.orders.mail.requested_subject', $vars))
            ->greeting(__('notifications.common.greeting', ['name' => $notifiable->name]))
            ->line(__('promotions.orders.mail.requested_line', $vars));

        if (filled($this->order->request_note)) {
            $message->line(__('promotions.orders.mail.requested_note', ['note' => (string) $this->order->request_note]));
        }

        return $message
            ->action(__('promotions.orders.mail.paid_action'), SponsorshipOrderResource::getUrl('index', panel: 'admin'))
            ->line(__('promotions.orders.mail.requested_why', $vars));
    }
}
