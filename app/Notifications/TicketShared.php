<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\AdmissionStatus;
use App\Models\AdmissionTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Il biglietto che chi ha prenotato manda a un partecipante. Il testo dice
 * chi l'ha inviato e che l'indirizzo non è stato salvato: chi lo riceve non
 * deve chiedersi perché un servizio che non conosce gli scrive.
 *
 * Il nome di chi invia e il nominativo sono testo libero di un utente, e la
 * mail parte dal nostro dominio verso un indirizzo qualsiasi. Se somigliano a
 * un indirizzo web o email non li scriviamo: i client di posta trasformano da
 * soli in link gli URL in chiaro, e la mail diventerebbe un volantino di
 * phishing firmato da noi.
 */
class TicketShared extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $ticketId, public string $senderName, public string $url) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return AdmissionTicket::query()->whereKey($this->ticketId)->where('status', AdmissionStatus::Valid)->exists();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ticket = AdmissionTicket::query()->with('booking.occurrence.event.city')->findOrFail($this->ticketId);
        $date = $ticket->booking?->occurrence;
        $timezone = $date?->event->city->timezone ?? config('app.timezone');

        $name = self::safeName($this->senderName) ?? __('ticketing.share.someone');
        $attendee = self::safeName($ticket->attendee_name);

        return (new MailMessage)
            ->subject(__('ticketing.share.mail_subject', ['name' => $name, 'title' => $date?->event->title ?? __('ticketing.title')]))
            ->line($attendee === null
                ? __('ticketing.share.mail_intro_unnamed', ['name' => $name])
                : __('ticketing.share.mail_intro', ['name' => $name, 'attendee' => $attendee]))
            ->line(($date?->event->title ?? '').' · '.($date?->starts_at->timezone($timezone)->format('d/m/Y H:i') ?? '').' · '.($date?->effectiveVenue()->name ?? ''))
            ->action(__('ticketing.share.mail_action'), $this->url)
            ->line(__('ticketing.share.mail_privacy', ['name' => $name]));
    }

    /**
     * Il nome così com'è, oppure null se è vuoto o contiene qualcosa che
     * somiglia a un URL, un dominio o un'email. Con `withSecuredEncoding` la
     * `&` passa intatta e CommonMark decodifica le entità: `https&#58;//…`
     * tornerebbe un indirizzo in chiaro, quindi un nome con entità non passa.
     */
    private static function safeName(?string $value): ?string
    {
        $value = trim((string) $value);
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $value === '' || $decoded !== $value || preg_match('~(https?:|www\.|://|@|[\p{L}0-9-]+\.[a-z]{2,}\b)~iu', $decoded) === 1 ? null : $value;
    }
}
