<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Venue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Il rapporto mensile a chi gestisce un locale.
 *
 * **Perché esiste.** I numeri sono già tutti nel pannello, e proprio per
 * questo non li guarda quasi nessuno: bisogna ricordarsi di entrare. Un
 * rapporto che arriva da solo il primo del mese è la differenza fra un dato
 * disponibile e un dato usato.
 *
 * **Ogni numero dice come è stato contato.** Le aperture si contano dal
 * browser, quindi chi blocca gli script non viene contato: sono stime al
 * ribasso, e scriverlo accanto al numero costa una riga ed evita l'unica
 * discussione che non si può vincere, quella su un numero che si è lasciato
 * credere esatto. Le presenze invece sono conteggi esatti: le ha registrate
 * qualcuno all'ingresso.
 *
 * **Non promette ricavi.** Una visualizzazione non è una persona e una
 * prenotazione non è un incasso: il rapporto riporta ciò che è stato contato e
 * si ferma lì.
 */
class VenueMonthlyReport extends Notification
{
    /**
     * @param  array<string, int>  $totals
     * @param  array<string, int>  $previous
     */
    public function __construct(
        private readonly Venue $venue,
        private readonly string $label,
        private readonly array $totals,
        private readonly array $previous,
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
        $message = (new MailMessage)
            ->subject(__('venues.report.subject', ['locale' => $this->venue->name, 'mese' => $this->label]))
            ->greeting(__('venues.report.greeting', ['locale' => $this->venue->name]))
            ->line(__('venues.report.window', ['mese' => $this->label]));

        foreach (['views', 'profile_views', 'saves', 'new_followers', 'bookings', 'check_ins'] as $metric) {
            $message->line('**'.__('venues.report.metrics.'.$metric).':** '.$this->value($metric));
        }

        return $message
            ->line(__('venues.report.method'))
            ->action(__('venues.report.action'), url('/gestione'))
            ->line(__('venues.report.unsubscribe'));
    }

    /**
     * Il numero del mese e, se il mese prima esisteva, quanto è cambiato. La
     * variazione compare solo quando c'è un termine di paragone: «+100%» su un
     * mese di esordio racconterebbe una crescita che non è mai avvenuta.
     */
    private function value(string $metric): string
    {
        $current = $this->totals[$metric] ?? 0;
        $before = $this->previous[$metric] ?? 0;
        $formatted = number_format($current, 0, ',', '.');

        if ($before === 0) {
            return $formatted;
        }

        $change = (int) round(($current - $before) / $before * 100);

        return $formatted.' '.__('venues.report.change', [
            'segno' => $change > 0 ? '+' : '',
            'valore' => $change,
            'prima' => number_format($before, 0, ',', '.'),
        ]);
    }
}
