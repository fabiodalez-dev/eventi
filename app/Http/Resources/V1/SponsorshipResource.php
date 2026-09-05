<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\EventOccurrence;
use App\Models\Sponsorship;
use App\Support\Api\ApiContext;

final class SponsorshipResource
{
    /** @return array<string, mixed> */
    public static function toArray(Sponsorship $sponsorship, ?EventOccurrence $occurrence, ApiContext $context): array
    {
        $day = now('UTC')->format('Y-m-d');
        $signature = hash_hmac(
            'sha256',
            $sponsorship->getKey().'|'.$sponsorship->placement->value.'|'.$day,
            (string) config('app.key'),
        );

        return [
            'id' => (int) $sponsorship->getKey(),
            'label' => __('sponsorships.label'),
            'placement' => $sponsorship->placement->value,
            'advertiser' => [
                'name' => (string) $sponsorship->advertiser_name,
                'url' => $sponsorship->advertiser_url,
            ],
            'occurrence' => $occurrence === null ? null : OccurrenceResource::toArray($occurrence, $context),
            'metric_token' => $signature,
            'metric_url' => route('api.v1.sponsorships.metric', ['sponsorship' => $sponsorship->getKey(), 'metric' => 'METRIC']),
        ];
    }
}
