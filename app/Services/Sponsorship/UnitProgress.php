<?php

declare(strict_types=1);

namespace App\Services\Sponsorship;

use App\Services\Sponsorship\Delivery\PacingState;
use App\Services\Sponsorship\Delivery\TrafficForecast;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A che punto è un'unità di consegna, detto in numeri e in una frase.
 *
 * L'unità è chi paga: un'abilitazione con tutte le sue righe, oppure una
 * campagna singola della redazione. Questo oggetto non legge niente: lo
 * costruisce `SponsorshipProgress` con i numeri già sommati, e qui si
 * traducono in una barra e in una frase («1.240 di 3.000 visualizzazioni, in
 * linea: al ritmo attuale arrivi all'obiettivo venerdì 28 novembre»).
 *
 * **Due prodotti.** A visualizzazioni (`goal` valorizzato) la barra è
 * consegnate ÷ obiettivo e lo stato viene da `PacingState`. A periodo la barra
 * è il tempo passato, e la frase dice le visualizzazioni senza obiettivo.
 *
 * **La fine vera.** `trueEnd` è la prima fra la fine del periodo e la fine
 * dell'ultima data coperta: dopo l'ultima data l'unità non compare più, quindi
 * le scadenze e la stima si leggono lì. Quando nessun evento ha date in
 * arrivo ma il periodo continua (`idle`) la fine resta quella del periodo,
 * perché un evento nuovo può ancora arrivare.
 *
 * Tutte le date sono nel fuso della città dell'unità.
 */
final readonly class UnitProgress
{
    public const HALTED_UNPAID = 'unpaid';

    public const HALTED_PAUSED = 'paused';

    public function __construct(
        public string $key,
        public ?int $goal,
        public int $delivered,
        public int $clicks,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public CarbonImmutable $trueEnd,
        public CarbonImmutable $now,
        public string $timezone,
        public ?PacingState $state,
        public float $expected,
        public ?float $quota,
        public ?CarbonImmutable $projectedDate,
        public ?int $projectedTotal,
        public bool $idle,
        public ?string $halted,
        public TrafficForecast $forecast,
    ) {}

    public function hasGoal(): bool
    {
        return $this->goal !== null;
    }

    public function started(): bool
    {
        return $this->now->greaterThanOrEqualTo($this->start);
    }

    public function ended(): bool
    {
        return $this->now->greaterThanOrEqualTo($this->trueEnd);
    }

    /** Vero quando l'unità a visualizzazioni ha consegnato tutto l'obiettivo. */
    public function completed(): bool
    {
        return $this->goal !== null && $this->delivered >= $this->goal;
    }

    /**
     * La lunghezza della barra, da 0 a 100: consegnate ÷ obiettivo, oppure il
     * tempo passato per il prodotto a periodo.
     */
    public function percent(): float
    {
        if ($this->goal !== null) {
            return $this->goal > 0 ? min(100.0, 100 * $this->delivered / $this->goal) : 100.0;
        }

        $total = $this->end->getTimestamp() - $this->start->getTimestamp();

        if ($total <= 0) {
            return $this->started() ? 100.0 : 0.0;
        }

        return min(100.0, max(0.0, 100 * ($this->now->getTimestamp() - $this->start->getTimestamp()) / $total));
    }

    /**
     * Dove dovrebbe essere la barra adesso, da 0 a 100: la tacca dell'atteso.
     * Solo mentre una consegna a visualizzazioni è in corso.
     */
    public function expectedPercent(): ?float
    {
        if ($this->goal === null || $this->goal <= 0 || ! $this->started() || $this->ended() || $this->completed() || $this->halted !== null) {
            return null;
        }

        return min(100.0, max(0.0, 100 * $this->expected / $this->goal));
    }

    /** Le visualizzazioni attese fino a un istante, seguendo il traffico previsto. */
    public function expectedUntil(CarbonInterface $instant): float
    {
        if ($this->goal === null) {
            return 0.0;
        }

        $until = CarbonImmutable::instance($instant)->setTimezone($this->timezone);
        $until = $until->greaterThan($this->trueEnd) ? $this->trueEnd : $until;

        return PacingState::expected($this->goal, $this->forecast->between($this->start, $until), $this->forecast->between($this->start, $this->trueEnd));
    }

    /** I giorni che restano fino alla fine vera; zero a periodo finito. */
    public function daysLeft(): int
    {
        if ($this->ended()) {
            return 0;
        }

        $from = $this->started() ? $this->now : $this->start;

        return (int) floor(($this->trueEnd->getTimestamp() - $from->getTimestamp()) / 86400);
    }

    /** Il giorno del periodo in cui siamo, contando da 1. */
    public function dayNumber(): int
    {
        return min($this->totalDays(), max(1, intdiv(max(0, $this->now->getTimestamp() - $this->start->getTimestamp()), 86400) + 1));
    }

    /** Quanti giorni dura il periodo, arrotondando in su. */
    public function totalDays(): int
    {
        return max(1, (int) ceil(($this->end->getTimestamp() - $this->start->getTimestamp()) / 86400));
    }

    /** Il colore di Filament della barra e del distintivo. */
    public function color(): string
    {
        return match (true) {
            $this->halted !== null && ! $this->ended() => 'gray',
            ! $this->started() => 'gray',
            $this->state !== null => $this->state->color(),
            $this->ended() => 'gray',
            default => 'primary',
        };
    }

    /** La parola del distintivo: lo stato, oppure «A periodo», «Programmata», «Sospesa». */
    public function badge(): string
    {
        $key = match (true) {
            $this->halted !== null && ! $this->ended() => $this->halted,
            ! $this->started() && ! $this->completed() => 'scheduled',
            $this->state !== null => $this->state->value,
            $this->ended() => 'ended',
            default => 'period',
        };

        return __('sponsorship-panel.state.'.$key);
    }

    /** «1.240 di 3.000 visualizzazioni», oppure «1.240 visualizzazioni». */
    public function numbers(): string
    {
        return $this->goal !== null
            ? __('sponsorship-panel.progress.goal_numbers', ['delivered' => self::number($this->delivered), 'goal' => self::number($this->goal)])
            : __('sponsorship-panel.progress.period_numbers', ['views' => self::number($this->delivered)]);
    }

    /** La frase sotto la barra. */
    public function phrase(): string
    {
        return $this->goal !== null ? $this->goalPhrase($this->goal) : $this->periodPhrase();
    }

    /** «venerdì 28 novembre», con l'anno se non è quello in corso. */
    public function dateText(CarbonInterface $date): string
    {
        $local = CarbonImmutable::instance($date)->setTimezone($this->timezone)->locale(app()->getLocale());

        return $local->isoFormat($local->year === $this->now->year ? 'dddd D MMMM' : 'dddd D MMMM YYYY');
    }

    public static function number(int|float $value): string
    {
        return number_format((float) $value, 0, ',', '.');
    }

    private function goalPhrase(int $goal): string
    {
        $vars = [
            'numbers' => $this->numbers(),
            'state' => $this->state !== null ? __('sponsorship-panel.state_inline.'.$this->state->value) : '',
            'end' => $this->dateText($this->trueEnd),
        ];

        return match (true) {
            $this->completed() => __('sponsorship-panel.progress.goal.completed', $vars),
            ! $this->started() => __('sponsorship-panel.progress.goal.scheduled', ['start' => $this->dateText($this->start), 'goal' => self::number($goal), 'end' => $vars['end']]),
            $this->ended() => __('sponsorship-panel.progress.goal.missed', $vars),
            $this->halted !== null => __('sponsorship-panel.progress.halted.'.$this->halted, $vars),
            $this->idle => __('sponsorship-panel.progress.goal.idle', $vars),
            $this->state === PacingState::AtRisk => __('sponsorship-panel.progress.goal.at_risk', $vars),
            $this->projectedDate !== null => __('sponsorship-panel.progress.goal.on_date', [...$vars, 'date' => $this->dateText($this->projectedDate)]),
            $this->projectedTotal !== null => __('sponsorship-panel.progress.goal.short', [...$vars, 'total' => self::number($this->projectedTotal)]),
            default => __('sponsorship-panel.progress.goal.early', $vars),
        };
    }

    private function periodPhrase(): string
    {
        $vars = [
            'views' => self::number($this->delivered),
            'clicks' => self::number($this->clicks),
            'start' => $this->dateText($this->start),
            'end' => $this->dateText($this->end),
            'day' => $this->dayNumber(),
            'days' => $this->totalDays(),
            'numbers' => $this->numbers(),
        ];

        return match (true) {
            ! $this->started() => __('sponsorship-panel.progress.period.scheduled', $vars),
            $this->ended() => __('sponsorship-panel.progress.period.ended', $vars),
            $this->halted !== null => __('sponsorship-panel.progress.halted.'.$this->halted, $vars),
            $this->idle => __('sponsorship-panel.progress.period.idle', $vars),
            default => __('sponsorship-panel.progress.period.running', $vars),
        };
    }
}
