<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Http\Middleware\Api\CacheJsonResponse;
use App\Models\EventOccurrence;
use App\Models\Sponsorship;
use App\Services\Sponsorship\Delivery\ImpressionToken;
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
            /*
             * Il gettone giornaliero di sempre: resta, perché le app già
             * installate lo mandano in `X-Metric-Token` e devono continuare a
             * contare.
             */
            'metric_token' => $signature,
            'metric_url' => route('api.v1.sponsorships.metric', ['sponsorship' => $sponsorship->getKey(), 'metric' => 'METRIC']),
            /*
             * Il gettone usa e getta di questa visualizzazione: nuovo a ogni
             * risposta, va rimandato in `X-Impression-Token` con la
             * visualizzazione e con il clic. Lo stesso gettone conta una volta
             * sola, quindi due client non devono condividerlo: la risposta che
             * lo porta si segna, e `CacheJsonResponse` la dichiara privata e
             * senza ETag invece che pubblica per un minuto.
             */
            'impression_token' => self::singleUse(ImpressionToken::issue($sponsorship, $sponsorship->placement)),
        ];
    }

    /** Segna la richiesta: la sua risposta porta un gettone usa e getta. */
    private static function singleUse(string $token): string
    {
        request()->attributes->set(CacheJsonResponse::SINGLE_USE, true);

        return $token;
    }
}
