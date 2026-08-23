<?php

declare(strict_types=1);

namespace App\Notifications\Scheduled;

use App\DTOs\NotificationMessage;
use App\Models\User;
use App\Support\Notifications\PreferenceLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
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

    public function __construct(public readonly NotificationMessage $message) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        /*
         * §15.6: l'archivio in-app c'è **sempre**, qualunque sia il canale
         * scelto per la consegna. È il posto dove ritrovare una notifica letta
         * di sfuggita e chiusa.
         */
        return ['mail', 'database'];
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
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->message->toArray();
    }
}
