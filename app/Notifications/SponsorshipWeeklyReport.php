<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Sponsorship;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Il riepilogo settimanale a chi ha pagato la campagna.
 *
 * **Perche' esiste.** `advertiser_email` veniva raccolta e non usata da
 * nessuna parte: ogni volta che un committente chiedeva come stesse andando,
 * la risposta era un lavoro a mano — aprire il pannello, leggere due numeri,
 * scrivere una mail. Con un cliente si sopporta; con cinque diventa il motivo
 * per cui non se ne prendono altri.
 *
 * **Dice sempre come sono contate le misure**, e non e' una formalita': si
 * contano dal browser, quindi chi blocca gli script non viene contato e le
 * cifre sono una stima al ribasso. Scriverlo ogni volta, accanto ai numeri,
 * costa una riga ed evita la sola discussione che non si puo' vincere — quella
 * su un numero che si e' lasciato credere esatto.
 *
 * **Non porta un collegamento a un pannello** perche' un pannello non c'e', e
 * non serve: i numeri che contano stanno tutti nel messaggio.
 */
class SponsorshipWeeklyReport extends Notification
{
    public function __construct(
        private readonly Sponsorship $campagna,
        private readonly CarbonImmutable $da,
        private readonly CarbonImmutable $a,
        private readonly int $viste,
        private readonly int $aperture,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $messaggio = (new MailMessage)
            /* `event` non e' mai nulla: la colonna e' obbligatoria a schema e
               la cancellazione dell'evento porta via la campagna. */
            ->subject(__('sponsorships.report.subject', [
                'evento' => $this->campagna->event->title,
            ]))
            ->greeting(__('sponsorships.report.greeting', [
                'nome' => $this->campagna->advertiser_name,
            ]))
            ->line(__('sponsorships.report.window', [
                'da' => $this->da->translatedFormat('j F'),
                'a' => $this->a->translatedFormat('j F Y'),
            ]))
            ->line('**'.__('sponsorships.metrics.impressions').':** '.number_format($this->viste, 0, ',', '.'))
            ->line('**'.__('sponsorships.metrics.clicks').':** '.number_format($this->aperture, 0, ',', '.'));

        /*
         * Il rapporto solo quando c'e' qualcosa da rapportare: su zero
         * visualizzazioni non e' «zero per cento», e' una domanda senza
         * risposta — e scritto come 0% farebbe concludere che la campagna
         * vada male quando non e' ancora partita.
         */
        if ($this->viste > 0) {
            $messaggio->line(__('sponsorships.report.rate', [
                'valore' => number_format($this->aperture / $this->viste * 100, 1, ',', '.'),
            ]));
        }

        $totali = $this->campagna;

        $messaggio
            ->line(__('sponsorships.report.total', [
                'viste' => number_format((int) $totali->impressions, 0, ',', '.'),
                'aperture' => number_format((int) $totali->clicks, 0, ',', '.'),
            ]))
            ->line(__('sponsorships.report.until', [
                'data' => $this->campagna->ends_at->translatedFormat('j F Y'),
            ]));

        return $messaggio
            ->action(
                __('sponsorships.report.see_event'),
                route('events.show', $this->campagna->event),
            )
            ->line(__('sponsorships.report.how_measured'));
    }
}
