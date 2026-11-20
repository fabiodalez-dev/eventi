<?php

declare(strict_types=1);

use App\Services\Sponsorship\Delivery\EventWeights;
use App\Services\Sponsorship\Delivery\Pacer;
use App\Services\Sponsorship\Delivery\PacingState;
use App\Services\Sponsorship\Delivery\QuotaAllocator;
use App\Services\Sponsorship\Delivery\TrafficForecast;
use Tests\TestCase;

/*
 * Le formule prese dal contenitore leggono i parametri da
 * `config/sponsorship.php`, e lo stato del pacing ha la sua etichetta.
 *
 * Qui serve l'applicazione; il resto delle prove delle formule non la avvia.
 */
uses(TestCase::class);

it('builds the formulas with the starting parameters', function (): void {
    expect(config('sponsorship.quota_max'))->toBe(0.8)
        ->and(app(Pacer::class)->quotaMax())->toBe(0.8)
        ->and(app(QuotaAllocator::class)->allocate([['key' => 'a', 'quota' => 0.9]])['shares'])->toBe(['a' => 0.8])
        ->and(app(EventWeights::class)->proximity(20))->toBe(0.1)
        ->and(app(EventWeights::class)->proximity(7))->toEqualWithDelta(exp(-1), 1e-12);
});

it('builds the formulas with the configured parameters', function (): void {
    config([
        'sponsorship.quota_max' => 0.6,
        'sponsorship.event_proximity_days' => 14,
        'sponsorship.event_weight_floor' => 0.2,
    ]);

    expect(app(Pacer::class)->quotaMax())->toBe(0.6)
        ->and(app(Pacer::class)->quota(100, 0, 10))->toBe(0.6)
        ->and(app(QuotaAllocator::class)->allocate([['key' => 'a', 'quota' => 0.9]])['shares'])->toBe(['a' => 0.6])
        ->and(app(EventWeights::class)->proximity(7))->toEqualWithDelta(exp(-0.5), 1e-12)
        ->and(app(EventWeights::class)->proximity(30))->toBe(0.2);
});

it('keeps the default thresholds equal to the configuration', function (): void {
    expect(config('sponsorship.pacing_thresholds'))->toBe(PacingState::DEFAULT_THRESHOLDS)
        ->and(config('sponsorship.quota_max'))->toBe(Pacer::DEFAULT_QUOTA_MAX)
        ->and(config('sponsorship.event_proximity_days'))->toEqual(EventWeights::DEFAULT_PROXIMITY_DAYS)
        ->and(config('sponsorship.event_weight_floor'))->toBe(EventWeights::DEFAULT_FLOOR)
        ->and(config('sponsorship.forecast_weights'))->toBe(TrafficForecast::DEFAULT_WEIGHTS)
        ->and(config('sponsorship.forecast_min_days'))->toBe(TrafficForecast::DEFAULT_MIN_DAYS);
});

it('labels each pacing state from the enums translation file', function (): void {
    app()->setLocale('it');
    app('translator')->addLines(['enums.sponsorship_pacing_state.on_track' => 'In linea'], 'it');

    expect(PacingState::OnTrack->label())->toBe('In linea')
        ->and(PacingState::options())->toHaveKeys(['on_track', 'slightly_behind', 'behind', 'ahead', 'at_risk', 'completed', 'missed'])
        ->and(PacingState::options()['on_track'])->toBe('In linea');
});
