<?php

declare(strict_types=1);

namespace App\Services\Sponsorship\Editorial;

use App\Models\City;
use App\Models\Sponsorship;
use App\Models\SponsorshipGrant;
use Carbon\CarbonImmutable;

/**
 * I dati della scheda «Avanzamento» di una campagna o di un'abilitazione:
 * l'unità a cui appartiene la riga, la consegna giorno per giorno e, per le
 * abilitazioni, i loro eventi. La stessa lettura per il pannello
 * (`SponsorshipResource::progressData`) e per l'API dell'app.
 */
final class ProgressDetail
{
    public function __construct(private readonly SponsorshipProgress $service) {}

    /**
     * @return array{progress: UnitProgress|null, daily?: list<array{day: CarbonImmutable, delivered: int, expected: int}>, events?: list<array<string, mixed>>, quotaMax?: float, minDays?: int, provisional?: bool}
     */
    public function for(Sponsorship|SponsorshipGrant|null $record): array
    {
        if ($record === null) {
            return ['progress' => null];
        }

        $grant = $record instanceof SponsorshipGrant ? $record : $record->grant;

        if ($grant instanceof SponsorshipGrant) {
            $unit = $this->service->forGrant($grant);
            $city = City::query()->find($grant->sponsorCityId());
            $placement = $grant->placement;
            $ids = Sponsorship::withTrashed()->where('sponsorship_grant_id', $grant->getKey())->pluck('id')->map(fn ($id): int => (int) $id)->all();
        } else {
            /* Senza abilitazione l'unità è la riga stessa. */
            $unit = $this->service->forSponsorship($record);
            $city = $record->city;
            $placement = $record->placement;
            $ids = [(int) $record->getKey()];
        }

        if ($unit === null) {
            return ['progress' => null];
        }

        $forecast = $city instanceof City ? $this->service->forecast($city, $placement) : null;

        return [
            'progress' => $unit,
            'daily' => $forecast === null ? [] : $this->service->daily($unit, $ids, $forecast),
            'events' => $grant instanceof SponsorshipGrant ? $this->service->grantEvents($grant) : [],
            'quotaMax' => $this->service->quotaMax(),
            'minDays' => (int) config('sponsorship.forecast_min_days', 14),
            'provisional' => $forecast?->isProvisional() ?? true,
        ];
    }
}
