<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A che punto è una campagna, **adesso**.
 *
 * **Non è una colonna e non deve diventarlo.** Si ricava dallo stato scelto da
 * una persona (`SponsorshipStatus`) più la finestra temporale, ed è il motivo
 * per cui dice sempre il vero: non c'è nessun processo notturno che debba
 * girare perché resti aggiornata. È la stessa ragione per cui `Scheduled` e
 * `Completed` non sono stati veri — lo spiega il commento in testa a
 * `SponsorshipStatus`.
 *
 * Serve a chi guarda l'elenco nel pannello. Prima c'era solo lo stato, e una
 * campagna finita da tre settimane si leggeva «attiva»: vero alla lettera —
 * nessuno l'ha sospesa — e inutile per chi sta cercando di capire cosa stia
 * girando in questo momento.
 */
enum SponsorshipPhase: string
{
    /** In lavorazione: non comparirà, qualunque cosa dica la finestra. */
    case Draft = 'draft';

    /** Approvata, ma la finestra non è ancora aperta. */
    case Scheduled = 'scheduled';

    /** Sta girando sul sito in questo momento. */
    case Running = 'running';

    /** La finestra si è chiusa. */
    case Ended = 'ended';

    /** Fermata da una persona a metà strada. */
    case Paused = 'paused';

    public function label(): string
    {
        return __('sponsorships.phase.'.$this->value);
    }

    /**
     * Il colore del segnale nell'elenco.
     *
     * Solo `Running` è colorata di verde: è l'unica riga che risponde alla
     * domanda «cosa sto pubblicando adesso», e in un elenco dove tutto è
     * colorato non risalta più niente.
     */
    public function color(): string
    {
        return match ($this) {
            self::Running => 'success',
            self::Scheduled => 'info',
            self::Paused => 'warning',
            self::Draft, self::Ended => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
