<?php

declare(strict_types=1);

use App\Enums\SponsorshipPacing;
use App\Services\Sponsorship\Delivery\PacingState;
use App\Services\Sponsorship\Delivery\TrafficForecast;
use Carbon\CarbonImmutable;

/*
 * Lo stato del pacing per i pannelli: ogni soglia e i suoi confini, l'ordine
 * fra obiettivo raggiunto, periodo chiuso e «a rischio», e l'atteso che segue
 * il traffico previsto invece del calendario.
 *
 * Nessuna applicazione: l'etichetta tradotta è provata in
 * `DeliveryConfigTest`.
 */

/** Obiettivo 1.000, metà del traffico previsto già passata: attese 500. */
function p1HalfWay(int $delivered, float $required = 0.3): PacingState
{
    return PacingState::evaluate(1000, $delivered, 50.0, 100.0, $required);
}

it('reads the ratio against the thresholds and their boundaries', function (int $delivered, PacingState $state): void {
    expect(p1HalfWay($delivered))->toBe($state);
})->with([
    'nothing yet' => [0, PacingState::Behind],
    'just below 0.7' => [349, PacingState::Behind],
    'exactly 0.7' => [350, PacingState::SlightlyBehind],
    'just below 0.9' => [449, PacingState::SlightlyBehind],
    'exactly 0.9' => [450, PacingState::OnTrack],
    'exactly expected' => [500, PacingState::OnTrack],
    'exactly 1.1' => [550, PacingState::OnTrack],
    'just above 1.1' => [551, PacingState::Ahead],
    'far ahead' => [990, PacingState::Ahead],
]);

it('says completed once the goal is reached, even after the end', function (): void {
    expect(PacingState::evaluate(1000, 1000, 50, 100))->toBe(PacingState::Completed)
        ->and(PacingState::evaluate(1000, 1200, 10, 100, 0.0, true))->toBe(PacingState::Completed)
        ->and(PacingState::evaluate(1000, 999, 100, 100, 0.0, true))->toBe(PacingState::Missed);
});

it('says at risk when the required quota passes the cap, whatever the ratio', function (): void {
    expect(p1HalfWay(500, 0.81))->toBe(PacingState::AtRisk)
        ->and(p1HalfWay(600, INF))->toBe(PacingState::AtRisk)
        ->and(p1HalfWay(500, 0.8))->toBe(PacingState::OnTrack)
        ->and(PacingState::evaluate(1000, 500, 50, 100, 0.6, false, PacingState::DEFAULT_THRESHOLDS, 0.5))->toBe(PacingState::AtRisk)
        // A periodo chiuso non si è più a rischio: si è chiusi sotto l'obiettivo.
        ->and(PacingState::evaluate(1000, 500, 100, 100, INF, true))->toBe(PacingState::Missed);
});

it('expects nothing before any forecast traffic has passed', function (): void {
    expect(PacingState::evaluate(1000, 0, 0, 100))->toBe(PacingState::OnTrack)
        ->and(PacingState::evaluate(1000, 0, 0, 0))->toBe(PacingState::OnTrack)
        ->and(PacingState::evaluate(1000, 0, 0, 100, 0.9))->toBe(PacingState::AtRisk);
});

it('does not judge a unit until one percent of the goal is expected', function (): void {
    /* Un'unità appena partita: 1 visualizzazione attesa su 1000, 0 consegnate.
       Fino a 10 attese (1%) è presto per dire «in ritardo»; da 10 si giudica. */
    expect(PacingState::evaluate(1000, 0, 0.1, 100))->toBe(PacingState::OnTrack)
        ->and(PacingState::evaluate(1000, 0, 0.99, 100))->toBe(PacingState::OnTrack)
        ->and(PacingState::evaluate(1000, 0, 1.0, 100))->toBe(PacingState::Behind)
        ->and(PacingState::evaluate(1000, 8, 1.0, 100))->toBe(PacingState::SlightlyBehind)
        // Chi è già oltre l'atteso lo è anche presto.
        ->and(PacingState::evaluate(1000, 5, 0.1, 100))->toBe(PacingState::Ahead)
        ->and(PacingState::evaluate(1000, 1, 0.1, 100))->toBe(PacingState::OnTrack)
        // «A rischio» resta più forte: l'obiettivo non ci sta comunque.
        ->and(PacingState::evaluate(1000, 0, 0.1, 100, 0.9))->toBe(PacingState::AtRisk);
});

it('computes the expected deliveries from the forecast share', function (): void {
    expect(PacingState::expected(3000, 1200, 6000))->toBe(600.0)
        ->and(PacingState::expected(3000, 0, 6000))->toBe(0.0)
        ->and(PacingState::expected(3000, 7000, 6000))->toBe(3000.0)
        ->and(PacingState::expected(3000, -5, 6000))->toBe(0.0)
        ->and(PacingState::expected(3000, 100, 0))->toBe(0.0)
        ->and(PacingState::ratio(450, 500.0))->toBe(0.9)
        ->and(PacingState::ratio(450, 0.0))->toBeNull();
});

it('follows custom thresholds and fills the missing ones', function (): void {
    $strict = ['late' => 0.8, 'on_track' => 0.95, 'ahead' => 1.05];

    expect(PacingState::evaluate(1000, 450, 50, 100, 0.3, false, $strict))->toBe(PacingState::SlightlyBehind)
        ->and(PacingState::evaluate(1000, 399, 50, 100, 0.3, false, $strict))->toBe(PacingState::Behind)
        ->and(PacingState::evaluate(1000, 530, 50, 100, 0.3, false, $strict))->toBe(PacingState::Ahead)
        ->and(PacingState::evaluate(1000, 360, 50, 100, 0.3, false, ['late' => 0.75]))->toBe(PacingState::Behind)
        ->and(PacingState::evaluate(1000, 440, 50, 100, 0.3, false, ['late' => 0.75]))->toBe(PacingState::SlightlyBehind)
        ->and(PacingState::evaluate(1000, 560, 50, 100, 0.3, false, ['late' => 0.75]))->toBe(PacingState::Ahead);
});

it('reads a unit in its period with the forecast traffic', function (): void {
    $start = CarbonImmutable::parse('2026-11-23 00:00', 'Europe/Rome');
    $end = $start->addDays(10);
    $forecast = TrafficForecast::flat(600);

    // Dopo 4 giorni ne erano attese 1.200.
    expect(PacingState::at(3000, 1200, $forecast, $start, $end, $start->addDays(4)))->toBe(PacingState::OnTrack)
        ->and(PacingState::at(3000, 1000, $forecast, $start, $end, $start->addDays(4)))->toBe(PacingState::SlightlyBehind)
        ->and(PacingState::at(3000, 600, $forecast, $start, $end, $start->addDays(4)))->toBe(PacingState::Behind)
        ->and(PacingState::at(3000, 1400, $forecast, $start, $end, $start->addDays(4)))->toBe(PacingState::Ahead)
        ->and(PacingState::at(3000, 0, $forecast, $start, $end, $start->subDay()))->toBe(PacingState::OnTrack)
        ->and(PacingState::at(3000, 2990, $forecast, $start, $end, $end))->toBe(PacingState::Missed)
        ->and(PacingState::at(3000, 2990, $forecast, $start, $end, $end->addDays(3)))->toBe(PacingState::Missed)
        ->and(PacingState::at(3000, 3000, $forecast, $start, $end, $end->addDays(3)))->toBe(PacingState::Completed);
});

it('flags a unit in its period that cannot reach the goal any more', function (): void {
    $start = CarbonImmutable::parse('2026-11-23 00:00', 'Europe/Rome');
    $end = $start->addDays(10);
    $forecast = TrafficForecast::flat(600);

    // Ultimo giorno a mezzogiorno: restano 300 visite previste, ne mancano 1.000.
    $noon = $end->subHours(12);

    expect(PacingState::at(3000, 2000, $forecast, $start, $end, $noon))->toBe(PacingState::AtRisk)
        // «Il prima possibile» chiede il tetto e non è a rischio: 2.000 su 2.850 attese.
        ->and(PacingState::at(3000, 2000, $forecast, $start, $end, $noon, SponsorshipPacing::Asap))->toBe(PacingState::SlightlyBehind)
        ->and(PacingState::at(3000, 2800, $forecast, $start, $end, $noon, quotaMax: 0.5))->toBe(PacingState::AtRisk)
        ->and(PacingState::at(3000, 2800, $forecast, $start, $end, $noon, thresholds: ['ahead' => 0.9]))->toBe(PacingState::Ahead);
});

it('expects less than half the goal at mid period when the weekend is still ahead', function (): void {
    // Da lunedì a domenica compresa, con venerdì e sabato più trafficati.
    $start = CarbonImmutable::parse('2026-11-23 00:00', 'Europe/Rome');
    $end = $start->addWeek();
    $forecast = TrafficForecast::startingCurve(400);
    $midWeek = $start->addHours(84);

    $expected = PacingState::expected(1000, $forecast->between($start, $midWeek), $forecast->between($start, $end));

    // Metà del calendario sarebbe 500; il traffico passato ne vale circa 435.
    expect(round($expected))->toBe(435.0)
        ->and(PacingState::at(1000, (int) round($expected), $forecast, $start, $end, $midWeek))->toBe(PacingState::OnTrack)
        ->and(PacingState::at(1000, 500, $forecast, $start, $end, $midWeek))->toBe(PacingState::Ahead);
});

it('colours each state for the panels', function (): void {
    expect(PacingState::OnTrack->color())->toBe('success')
        ->and(PacingState::Completed->color())->toBe('success')
        ->and(PacingState::Ahead->color())->toBe('info')
        ->and(PacingState::SlightlyBehind->color())->toBe('warning')
        ->and(PacingState::Behind->color())->toBe('danger')
        ->and(PacingState::AtRisk->color())->toBe('danger')
        ->and(PacingState::Missed->color())->toBe('gray');
});

it('asks for attention only while something can still be done', function (): void {
    $attention = array_values(array_filter(PacingState::cases(), fn (PacingState $state): bool => $state->needsAttention()));

    expect($attention)->toBe([PacingState::Behind, PacingState::AtRisk]);
});
