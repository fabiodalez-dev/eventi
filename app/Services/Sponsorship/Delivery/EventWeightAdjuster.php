<?php

declare(strict_types=1);

namespace App\Services\Sponsorship\Delivery;

use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Bind;

/**
 * Il punto di estensione del peso degli eventi dentro un'unità.
 *
 * Il piano dà a ogni evento il peso della vicinanza della data (`EventWeights`).
 * Qui si può moltiplicare quel peso per qualcos'altro che riguarda **solo gli
 * eventi dello stesso committente**: nel secondo rilascio (P7) è ciò che si è
 * imparato dai clic. Fra committenti diversi non entra niente: lì decide ciò
 * che hanno comprato.
 *
 * Nel primo rilascio la realizzazione è neutra e lascia i pesi come sono.
 */
#[Bind(NeutralEventWeightAdjuster::class)]
interface EventWeightAdjuster
{
    /**
     * I pesi dell'unità, eventualmente corretti. Le chiavi sono gli id delle
     * righe `sponsorships`; un peso a zero toglie la riga dall'estrazione.
     *
     * @param  array<int, float>  $weights
     * @return array<int, float>
     */
    public function adjust(string $unitKey, array $weights, CarbonImmutable $now): array;
}
