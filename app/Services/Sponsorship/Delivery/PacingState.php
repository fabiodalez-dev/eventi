<?php

declare(strict_types=1);

namespace App\Services\Sponsorship\Delivery;

use App\Enums\SponsorshipPacing;
use Carbon\CarbonInterface;

/**
 * A che punto è un'unità a visualizzazioni, in una parola.
 *
 *     atteso = G × (traffico previsto già passato ÷ traffico previsto di tutto il periodo)
 *
 * Il rapporto `consegnate ÷ atteso` dà lo stato: da 0,9 a 1,1 «in linea», fra
 * 0,7 e 0,9 «in leggero ritardo», sotto 0,7 «in ritardo», sopra 1,1 «in
 * anticipo». Le soglie vengono da `sponsorship.pacing_thresholds`. «A
 * rischio» quando la quota richiesta supera il tetto: l'obiettivo non ci sta
 * nel traffico che resta, comunque vada il rapporto.
 *
 * L'attesa segue il traffico previsto e non il calendario: a metà periodo, se
 * la seconda metà ha il fine settimana, l'atteso è meno di metà obiettivo.
 *
 * Nessun database e nessun orologio: i numeri entrano come parametri.
 */
enum PacingState: string
{
    case OnTrack = 'on_track';
    case SlightlyBehind = 'slightly_behind';
    case Behind = 'behind';
    case Ahead = 'ahead';
    case AtRisk = 'at_risk';
    case Completed = 'completed';
    case Missed = 'missed';

    /** Le soglie di partenza, uguali a `config/sponsorship.php`. */
    public const DEFAULT_THRESHOLDS = ['late' => 0.7, 'on_track' => 0.9, 'ahead' => 1.1];

    /**
     * Sotto questa parte dell'obiettivo l'atteso è troppo piccolo per
     * giudicare: un'unità appena partita con 1 visualizzazione attesa e 0
     * consegnate non è «in ritardo». Senza, l'avviso del mattino partirebbe
     * nelle prime ore di ogni campagna.
     */
    public const MIN_EXPECTED_SHARE = 0.01;

    /**
     * Lo stato dai numeri.
     *
     * L'ordine conta: obiettivo raggiunto prima di tutto, poi periodo chiuso
     * sotto l'obiettivo, poi «a rischio», poi il rapporto con l'atteso. Finché
     * l'atteso resta sotto l'1% dell'obiettivo (`MIN_EXPECTED_SHARE`) è presto
     * per dire «in ritardo»: in linea, oppure in anticipo se lo è già.
     *
     * @param  array{late?: float, on_track?: float, ahead?: float}  $thresholds
     */
    public static function evaluate(
        int $goal,
        int $delivered,
        float $elapsedForecast,
        float $totalForecast,
        float $requiredQuota = 0.0,
        bool $ended = false,
        array $thresholds = self::DEFAULT_THRESHOLDS,
        float $quotaMax = Pacer::DEFAULT_QUOTA_MAX,
    ): self {
        if ($delivered >= $goal) {
            return self::Completed;
        }

        if ($ended) {
            return self::Missed;
        }

        if ($requiredQuota > $quotaMax) {
            return self::AtRisk;
        }

        $expected = self::expected($goal, $elapsedForecast, $totalForecast);
        $ratio = self::ratio($delivered, $expected);

        if ($ratio === null) {
            return self::OnTrack;
        }

        $thresholds += self::DEFAULT_THRESHOLDS;
        $early = $expected < $goal * self::MIN_EXPECTED_SHARE;

        return match (true) {
            $ratio > $thresholds['ahead'] => self::Ahead,
            $early => self::OnTrack,
            $ratio < $thresholds['late'] => self::Behind,
            $ratio < $thresholds['on_track'] => self::SlightlyBehind,
            default => self::OnTrack,
        };
    }

    /**
     * Lo stato di un'unità nel suo periodo, all'istante `$now`.
     *
     * `$end` è la fine vera (`Pacer::trueEnd()`). La quota richiesta si
     * calcola qui con lo stesso `Pacer` della consegna.
     *
     * @param  array{late?: float, on_track?: float, ahead?: float}  $thresholds
     */
    public static function at(
        int $goal,
        int $delivered,
        TrafficForecast $forecast,
        CarbonInterface $start,
        CarbonInterface $end,
        CarbonInterface $now,
        SponsorshipPacing $pacing = SponsorshipPacing::Even,
        array $thresholds = self::DEFAULT_THRESHOLDS,
        float $quotaMax = Pacer::DEFAULT_QUOTA_MAX,
    ): self {
        $pacer = new Pacer($quotaMax);
        $until = $now->lessThan($end) ? $now : $end;

        return self::evaluate(
            $goal,
            $delivered,
            $forecast->between($start, $until),
            $forecast->between($start, $end),
            $pacer->requiredUntil($goal, $delivered, $forecast, $now, $end, $pacing),
            $now->greaterThanOrEqualTo($end),
            $thresholds,
            $pacer->quotaMax(),
        );
    }

    /**
     * Le visualizzazioni attese finora: l'obiettivo per la parte di traffico
     * previsto già passata. Zero se il periodo non ha traffico previsto.
     */
    public static function expected(int $goal, float $elapsedForecast, float $totalForecast): float
    {
        if ($totalForecast <= 0) {
            return 0.0;
        }

        return $goal * min(1.0, max(0.0, $elapsedForecast / $totalForecast));
    }

    /** Consegnate ÷ attese, oppure `null` se non ci si aspetta ancora niente. */
    public static function ratio(int $delivered, float $expected): ?float
    {
        return $expected > 0 ? $delivered / $expected : null;
    }

    public function label(): string
    {
        return __('enums.sponsorship_pacing_state.'.$this->value);
    }

    /** Il colore di Filament per il distintivo. */
    public function color(): string
    {
        return match ($this) {
            self::OnTrack, self::Completed => 'success',
            self::Ahead => 'info',
            self::SlightlyBehind => 'warning',
            self::Behind, self::AtRisk => 'danger',
            self::Missed => 'gray',
        };
    }

    /**
     * Vero per gli stati che la redazione deve guardare mentre si può ancora
     * fare qualcosa (`sponsorships:pacing-check`): «in ritardo» e «a rischio».
     * Una campagna chiusa sotto l'obiettivo la dice il rapporto, non l'avviso.
     */
    public function needsAttention(): bool
    {
        return $this === self::Behind || $this === self::AtRisk;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
