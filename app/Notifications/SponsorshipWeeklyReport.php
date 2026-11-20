<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Sponsorship;
use App\Services\Sponsorship\UnitProgress;
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
 *
 * **Parla dell'unità che si è comprata.** Con l'avanzamento (`UnitProgress`)
 * il messaggio dice anche obiettivo, consegnate e stato; per le righe di
 * un'abilitazione i numeri sono quelli di tutta l'abilitazione, non del
 * singolo evento. Il tasso di aperture compare solo da
 * `sponsorship.min_impressions_for_ctr` visualizzazioni in su.
 */
class SponsorshipWeeklyReport extends Notification
{
    public function __construct(
        private readonly Sponsorship $campagna,
        private readonly CarbonImmutable $da,
        private readonly CarbonImmutable $a,
        private readonly int $viste,
        private readonly int $aperture,
        private readonly ?UnitProgress $avanzamento = null,
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
        $unita = $this->campagna->sponsorship_grant_id !== null && $this->avanzamento !== null;

        $messaggio = (new MailMessage)
            /* `event` non e' mai nulla: la colonna e' obbligatoria a schema e
               la cancellazione dell'evento porta via la campagna. Per
               un'abilitazione il titolo e' il committente: gli eventi sono
               molti. */
            ->subject($unita
                ? __('sponsorship-panel.report.subject_unit', ['sponsor' => $this->campagna->advertiser_name])
                : __('sponsorships.report.subject', ['evento' => $this->campagna->event->title]))
            ->greeting(__('sponsorships.report.greeting', [
                'nome' => $this->campagna->advertiser_name,
            ]))
            ->line(__('sponsorships.report.window', [
                'da' => $this->da->translatedFormat('j F'),
                'a' => $this->a->translatedFormat('j F Y'),
            ]));

        if ($unita) {
            $messaggio->line(__('sponsorship-panel.report.unit_line'));
        }

        $messaggio
            ->line('**'.__('sponsorships.metrics.impressions').':** '.number_format($this->viste, 0, ',', '.'))
            ->line('**'.__('sponsorships.metrics.clicks').':** '.number_format($this->aperture, 0, ',', '.'));

        /*
         * Il rapporto solo quando c'e' abbastanza da rapportare: su zero
         * visualizzazioni non e' «zero per cento», e' una domanda senza
         * risposta, e su poche decine un clic in piu' raddoppia il numero.
         * Scritto lo stesso farebbe concludere che la campagna vada male (o
         * benissimo) quando non si sa ancora.
         */
        $minimo = self::minimoPerIlTasso();

        if ($this->viste >= $minimo) {
            $messaggio->line(__('sponsorships.report.rate', [
                'valore' => number_format($this->aperture / $this->viste * 100, 1, ',', '.'),
            ]));
        } elseif ($this->viste > 0) {
            $messaggio->line(__('sponsorship-panel.report.rate_threshold', ['min' => number_format($minimo, 0, ',', '.')]));
        }

        $messaggio->line(__('sponsorships.report.total', [
            'viste' => number_format($this->avanzamento->delivered ?? (int) $this->campagna->impressions, 0, ',', '.'),
            'aperture' => number_format($this->avanzamento->clicks ?? (int) $this->campagna->clicks, 0, ',', '.'),
        ]));

        if ($this->avanzamento !== null) {
            $this->avanzamento($messaggio, $this->avanzamento);
        }

        $messaggio->line(__('sponsorships.report.until', [
            'data' => $this->campagna->ends_at->translatedFormat('j F Y'),
        ]));

        return $messaggio
            ->action(
                __('sponsorships.report.see_event'),
                route('events.show', $this->campagna->event),
            )
            ->line(__('sponsorships.report.how_measured'));
    }

    /** Obiettivo, consegnate e stato; per il prodotto a periodo, che un obiettivo non c'e'. */
    private function avanzamento(MailMessage $messaggio, UnitProgress $avanzamento): void
    {
        if ($avanzamento->goal === null) {
            $messaggio->line(__('sponsorship-panel.report.period'));

            return;
        }

        $messaggio
            ->line('**'.__('sponsorship-panel.report.goal', ['goal' => UnitProgress::number($avanzamento->goal)]).'**')
            ->line(__('sponsorship-panel.report.delivered', [
                'delivered' => UnitProgress::number($avanzamento->delivered),
                'percent' => UnitProgress::number(floor($avanzamento->percent())),
            ]))
            ->line(__('sponsorship-panel.report.state', ['state' => mb_strtolower($avanzamento->badge())]))
            ->line($avanzamento->phrase());
    }

    private static function minimoPerIlTasso(): int
    {
        $minimo = config('sponsorship.min_impressions_for_ctr', 100);

        return is_numeric($minimo) ? max(1, (int) $minimo) : 100;
    }
}
