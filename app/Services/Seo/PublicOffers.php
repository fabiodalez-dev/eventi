<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Enums\TicketTierStatus;
use App\Http\Resources\V1\PriceResource;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Services\Ticketing\TicketingService;
use App\Support\EventUrl;
use App\Support\SafeUrl;
use App\Support\TicketTiers;

final class PublicOffers
{
    /** @return list<array<string, mixed>> */
    public function for(Event $event, EventOccurrence $date): array
    {
        if ($date->effective_ends_at->isPast()) {
            return [];
        }
        $url = EventUrl::occurrence($date);
        if ($date->booking_enabled && $date->effectiveVenue()?->ticketing_enabled) {
            $booking = app(TicketingService::class)->availability($date);

            return [$this->offer($date, '0.00', 'EUR', route('tickets.create', $date), TicketTierStatus::from($booking['sale_state']), $booking['opens_at'])];
        }
        $offers = [];
        foreach (TicketTiers::for($event, $date) as $tier) {
            if ($tier->price !== null) {
                $offers[] = ['name' => $tier->name, ...$this->offer($date, (string) $tier->price, $tier->currency,
                    SafeUrl::href($tier->url) ?? SafeUrl::href($event->ticket_url) ?? $url, $tier->status)];
            }
        }
        if ($offers !== []) {
            return $offers;
        }
        $price = PriceResource::toArray($event, $date);
        $amount = $price['type'] === PriceType::Free->value ? 0 : $price['min'];
        if ($price['type'] === PriceType::Unknown->value || $amount === null) {
            return [];
        }

        return [$this->offer($date, number_format((float) $amount, 2, '.', ''), $price['currency'] ?? 'EUR',
            SafeUrl::href($event->ticket_url) ?? $url, TicketTierStatus::Available)];
    }

    /** @return array<string, mixed> */
    private function offer(EventOccurrence $date, string $price, string $currency, string $url, TicketTierStatus $state, ?string $validFrom = null): array
    {
        $availability = match (true) {
            $date->status === OccurrenceStatus::Cancelled => 'Discontinued',
            $date->status === OccurrenceStatus::Postponed => null,
            $date->status === OccurrenceStatus::SoldOut => 'SoldOut',
            default => match ($state) {
                TicketTierStatus::Available => 'InStock', TicketTierStatus::SoldOut => 'SoldOut',
                TicketTierStatus::NotYetOnSale => null, TicketTierStatus::Closed => 'Discontinued',
            },
        };

        return array_filter(['@type' => 'Offer', 'price' => $price, 'priceCurrency' => $currency,
            'validFrom' => $validFrom, 'url' => $url, 'availability' => $availability === null ? null : 'https://schema.org/'.$availability],
            fn ($value): bool => $value !== null);
    }
}
