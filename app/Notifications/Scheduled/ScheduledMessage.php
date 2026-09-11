<?php

declare(strict_types=1);

namespace App\Notifications\Scheduled;

use App\DTOs\NotificationMessage;
use App\Enums\NotificationChannel;
use App\Jobs\Middleware\RespectNotificationPreferences;
use App\Models\User;
use App\Services\Notifications\ChannelSelector;
use App\Support\Features;
use App\Support\Notifications\PreferenceLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;
use Symfony\Component\Mime\Email;

/**
 * Il messaggio che parte davvero, su tutti i canali attivi (§15.6).
 *
 * È **una** classe per tutte le tipologie di §15.4, e non dieci: il contenuto
 * arriva già composto da `MessageFactory` dentro un `NotificationMessage`.
 * Dieci classi avrebbero significato dieci volte la stessa impalcatura —
 * canali, intestazioni, piè di pagina, disiscrizione — con dieci occasioni di
 * dimenticarne un pezzo proprio dove conta, cioè nel piè di pagina che la
 * legge (§15.9) rende obbligatorio.
 *
 * La tipologia resta comunque leggibile nell'archivio: `databaseType()`
 * scrive in `notifications.type` il valore di dominio (`event_reminder`) e non
 * il nome di questa classe, che a un'app non direbbe nulla.
 *
 * `ShouldQueue` non è un dettaglio di prestazioni: il worker delle notifiche
 * tiene le righe che sta elaborando sotto blocco di riga (`FOR UPDATE SKIP
 * LOCKED`), e consegnare la posta dentro quel blocco lo terrebbe aperto per
 * tutta la durata di una connessione SMTP.
 */
final class ScheduledMessage extends Notification implements ShouldQueue
{
    use Queueable;

    /*
     * Nessun `afterCommit`, ed è voluto. Il worker accoda dentro la stessa
     * transazione in cui segna la riga come inviata: il lavoro di coda vive in
     * una tabella del medesimo database, quindi diventa visibile agli altri
     * processi **soltanto** al commit. Se la transazione si ribalta, si
     * ribaltano insieme lo stato della riga e il messaggio: nessuna delle due
     * metà può sopravvivere all'altra.
     */

    /**
     * Il canale arriva già scelto da `ChannelSelector` (§15.6): qui non si
     * decide, si esegue. Il valore predefinito è l'email perché è il canale
     * che c'è sempre — chi costruisce questa notifica senza dichiarare nulla
     * (un test, un invio a mano) ottiene il comportamento di prima di D54.
     */
    public function __construct(
        public readonly NotificationMessage $message,
        public readonly NotificationChannel $channel = NotificationChannel::Mail,
    ) {}

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (! $notifiable instanceof User || ! $notifiable->canReceiveNotifications() || $notifiable->trashed()) {
            return false;
        }
        $type = $this->message->type;

        return $type->isEnabledFor($notifiable) && (! $type->isMarketing() || Features::newsletterActive());
    }

    /** @return array<int, object> */
    public function middleware(object $notifiable, string $channel): array
    {
        return [new RespectNotificationPreferences];
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        /*
         * §15.6: l'archivio in-app c'è **sempre**, qualunque sia il canale
         * scelto per la consegna. È il posto dove ritrovare una notifica letta
         * di sfuggita e chiusa — ed è anche ciò che rende innocuo il caso in
         * cui l'ultima iscrizione push sparisca fra la scelta del canale e la
         * consegna: `WebPushChannel` in quel caso esce in silenzio, e la
         * notifica resta comunque leggibile dentro il sito.
         *
         * Il canale push si dichiara con il nome della classe e non con una
         * stringa: il pacchetto non registra alcun driver `webpush` nel
         * gestore dei canali, e `via()` con una stringa sconosciuta solleva.
         */
        if ($this->channel === NotificationChannel::Database) {
            return ['database'];
        }
        if ($this->channel === NotificationChannel::Mail) {
            return ['mail', 'database'];
        }

        $delivery = $this->channel === NotificationChannel::Both ? ['mail'] : [];
        $selector = app(ChannelSelector::class);

        if ($selector->webConfigured()) {
            $delivery[] = WebPushChannel::class;
        }

        if ($selector->fcmConfigured()) {
            $delivery[] = FcmChannel::class;
        }

        return [...$delivery, 'database'];
    }

    /**
     * Il tipo di dominio, non il nome della classe PHP: è ciò che
     * `GET /v1/me/notifications` restituisce e su cui un'app decide l'icona.
     */
    public function databaseType(object $notifiable): string
    {
        return $this->message->type->value;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $unsubscribe = $notifiable instanceof User
            ? PreferenceLinks::unsubscribe($notifiable, $this->message->type)
            : null;

        $unsubscribePost = $notifiable instanceof User
            ? PreferenceLinks::unsubscribePost($notifiable, $this->message->type)
            : null;

        $mail = (new MailMessage)
            ->subject($this->message->subject)
            /*
             * La chiave si chiama `notification` e non `message`: Laravel
             * inietta da sé nella vista un `$message`, che è l'oggetto del
             * mailer. Chiamare così il nostro contenuto lo farebbe sparire
             * dietro il suo, e l'errore comparirebbe solo al momento di
             * comporre davvero il messaggio.
             */
            ->view('mail.notification', [
                'notification' => $this->message,
                'preferencesUrl' => $notifiable instanceof User ? PreferenceLinks::preferences($notifiable) : null,
                'unsubscribeUrl' => $unsubscribe,
            ]);

        if ($unsubscribePost === null) {
            return $mail;
        }

        /*
         * Disiscrizione a un click anche dal client di posta (RFC 8058): le due
         * intestazioni insieme fanno comparire il pulsante nativo di Gmail e
         * Apple Mail, che chiama l'indirizzo in `POST` senza aprire il browser.
         */
        return $mail->withSymfonyMessage(static function (Email $email) use ($unsubscribe, $unsubscribePost): void {
            $headers = $email->getHeaders();
            $headers->addTextHeader('List-Unsubscribe', sprintf('<%s>, <%s>', $unsubscribePost, $unsubscribe));
            $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        });
    }

    /**
     * La stessa notifica sullo schermo di un telefono (§15.4).
     *
     * Il testo non si ricompone qui: arriva già fatto in `NotificationMessage`,
     * che esiste proprio perché il messaggio archiviato e quello consegnato
     * non possano dire due cose diverse.
     *
     * `data.url` è il collegamento profondo che §15.4 pretende su ogni
     * notifica: il service worker apre quello e mai la home. `tag` è la
     * tipologia, così due riepiloghi che si accavallano si sostituiscono
     * invece di impilarsi — su un telefono la seconda copia della stessa cosa
     * è rumore, non informazione.
     *
     * Il piè di pagina con la disiscrizione resta una faccenda dell'email:
     * una notifica di sistema non ha spazio per un collegamento legale, e
     * l'interruttore sta nella pagina che il tocco apre.
     */
    public function toWebPush(object $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->message->heading)
            ->body(implode(' ', $this->message->lines))
            ->tag($this->message->type->value)
            ->data([
                'type' => $this->message->type->value,
                'url' => $this->message->url,
            ]);
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return FcmMessage::create()
            ->data([
                'title' => $this->message->heading,
                'body' => mb_substr(implode(' ', $this->message->lines), 0, 1000),
                'user_id' => $notifiable instanceof User ? (string) $notifiable->getKey() : '',
                'type' => $this->message->type->value,
                'url' => $this->message->url,
                'occurrence_id' => $this->message->occurrenceId === null ? '' : (string) $this->message->occurrenceId,
                'event_id' => $this->message->eventId === null ? '' : (string) $this->message->eventId,
            ])
            ->android([
                'priority' => 'high',
                'ttl' => '3600s',
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->message->toArray();
    }
}
