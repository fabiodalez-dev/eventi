<?php

declare(strict_types=1);

use App\Services\Sponsorship\Delivery\EventWeights;
use App\Services\Sponsorship\Delivery\Pacer;
use App\Services\Sponsorship\Delivery\PacingState;
use App\Services\Sponsorship\Delivery\QuotaAllocator;
use App\Services\Sponsorship\Delivery\TrafficForecast;

/*
 * Le formule sono funzioni pure: niente database, niente cache, niente
 * orologio, niente modelli, niente caso. Il tempo entra come parametro e il
 * caso come numero già estratto (`Draw`).
 */

arch('the delivery formulas do not read the database, the cache, the clock or a model')
    ->expect([Pacer::class, TrafficForecast::class, EventWeights::class, QuotaAllocator::class, PacingState::class])
    ->not->toUse([
        'Illuminate\Support\Facades',
        'Illuminate\Database',
        'Illuminate\Cache',
        'Illuminate\Support\Carbon',
        'Carbon\Carbon',
        'App\Models',
        'now',
        'today',
        'cache',
        'config',
        'rand',
        'mt_rand',
        'random_int',
        'time',
        'microtime',
    ]);
