<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\City;
use App\Services\Events\SameNightNearby;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;

/**
 * «Stessa sera in zona», accanto all'orario di una data nei due pannelli.
 *
 * Ogni modulo sa da sé dove sta la data e in che fuso è scritto l'orario,
 * quindi passa una funzione che, dallo stato del modulo, restituisce il
 * contesto; il riquadro chiede a `SameNightNearby` e si mostra solo quando
 * c'è qualcosa da dire. Un avviso, non un blocco: la sera la sceglie il
 * gestore.
 */
final class SameNightNearbyField
{
    /** @var array<string, list<array{title: string, place: string, time: string, category: string, same_category: bool, distance_m: int, url: string}>> */
    private static array $memo = [];

    /**
     * @param  Closure(Get): (array{city: City, lat: float, lng: float, start: CarbonImmutable, end: CarbonImmutable|null, venue_id?: int|null, category_id?: int|null, event_id?: int|null}|null)  $context
     */
    public static function make(Closure $context, string $name = 'same_night_nearby'): TextEntry
    {
        return TextEntry::make($name)
            ->label(__('manage.same_night.title'))
            ->helperText(__('manage.same_night.hint', ['km' => (int) SameNightNearby::RADIUS_KM]))
            ->state(fn (Get $get): string => self::render(self::items($context($get))))
            ->html()
            ->hidden(fn (Get $get): bool => self::items($context($get)) === [])
            ->columnSpanFull();
    }

    /**
     * Legge un orario del modulo: le stringhe vuote o malformate non sono un
     * errore da mostrare, solo l'assenza di una sera da confrontare.
     */
    public static function time(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value, $timezone);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array{city: City, lat: float, lng: float, start: CarbonImmutable, end: CarbonImmutable|null, venue_id?: int|null, category_id?: int|null, event_id?: int|null}|null  $context
     * @return list<array{title: string, place: string, time: string, category: string, same_category: bool, distance_m: int, url: string}>
     */
    private static function items(?array $context): array
    {
        if ($context === null) {
            return [];
        }
        $key = implode('|', [$context['city']->getKey(), $context['lat'], $context['lng'], $context['start']->toIso8601String(),
            $context['end']?->toIso8601String(), $context['venue_id'] ?? '', $context['category_id'] ?? '', $context['event_id'] ?? '']);

        return self::$memo[$key] ??= app(SameNightNearby::class)->around($context['city'], $context['lat'], $context['lng'], $context['start'],
            $context['end'], $context['venue_id'] ?? null, $context['category_id'] ?? null, $context['event_id'] ?? null);
    }

    /** @param list<array{title: string, place: string, time: string, category: string, same_category: bool, distance_m: int, url: string}> $items */
    private static function render(array $items): string
    {
        return view('filament.partials.same-night-nearby', ['items' => $items])->render();
    }
}
