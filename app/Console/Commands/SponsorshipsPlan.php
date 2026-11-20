<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\City;
use App\Services\Sponsorship\Delivery\DeliveryPlanBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Ricostruisce ogni minuto i piani di consegna delle sponsorizzazioni.
 *
 * Il piano dice chi può comparire adesso e con quale quota, per ogni città,
 * collocazione e per il banner. Chi serve una pagina lo legge dalla cache e
 * fa solo un'estrazione: è ciò che permette a card e cima dell'elenco di
 * cambiare a ogni visita senza nessuna query, e a una campagna in pausa, finita
 * o arrivata all'obiettivo di sparire entro un minuto.
 *
 * Se il cron si ferma non si rompe niente: un piano più vecchio di
 * `sponsorship.plan_ttl_seconds` lo ricostruisce chi serve la pagina.
 */
class SponsorshipsPlan extends Command
{
    protected $signature = 'sponsorships:plan {--city= : Solo questa città (id)}';

    protected $description = 'Ricostruisce i piani di consegna delle sponsorizzazioni';

    public function handle(DeliveryPlanBuilder $builder): int
    {
        $now = CarbonImmutable::now('UTC');
        $cities = City::query()->active()
            ->when($this->option('city'), fn ($query, $id) => $query->whereKey((int) $id))
            ->get();
        $plans = 0;
        $units = 0;

        foreach ($cities as $city) {
            foreach (DeliveryPlanBuilder::places() as $place) {
                $plan = $builder->refresh($city, $place, $now);
                $plans++;
                $units += count($plan->units());
            }
        }

        $this->info("Piani ricostruiti: {$plans}, unità in consegna: {$units}.");

        return self::SUCCESS;
    }
}
