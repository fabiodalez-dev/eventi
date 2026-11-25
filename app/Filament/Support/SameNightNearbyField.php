<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\City;
use App\Models\Venue;
use App\Services\Events\EventDateChecks;
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
    /**
     * Il risultato vale per una sola richiesta: `state()` e `hidden()` lo
     * chiedono entrambi, ma in un processo che resta acceso non deve
     * sopravvivere alle date pubblicate o annullate nel frattempo.
     *
     * @var array<string, list<array{title: string, place: string, time: string, category: string, same_category: bool, distance_m: int, url: string}>>
     */
    private static array $memo = [];

    private static ?int $memoRequest = null;

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
     * Il contesto che serve a `make()`, uguale per tutti i moduli: ciascuno
     * passa i luoghi candidati in ordine di preferenza (un locale, oppure un
     * indirizzo a mano con `lat`/`lng`) e vince il primo con le coordinate.
     *
     * @param  list<Venue|array<string, mixed>|null>  $places
     * @return array{city: City, lat: float, lng: float, start: CarbonImmutable, end: CarbonImmutable|null, venue_id: int|null, category_id: int|null, event_id: int|null}|null
     */
    public static function context(?City $city, array $places, mixed $start, mixed $end, string $timezone, ?int $venueId = null, mixed $categoryId = null, ?int $eventId = null): ?array
    {
        // La regola sta in `EventDateChecks`, la stessa che usa l'API di gestione.
        return EventDateChecks::context($city, $places, $start, $end, $timezone, $venueId, $categoryId, $eventId);
    }

    /** Un orario del modulo, o `null` se vuoto o malformato (`EventDateChecks::time`). */
    public static function time(mixed $value, string $timezone): ?CarbonImmutable
    {
        return EventDateChecks::time($value, $timezone);
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
        $request = app()->bound('request') ? spl_object_id(app('request')) : 0;
        if (self::$memoRequest !== $request) {
            self::$memo = [];
            self::$memoRequest = $request;
        }
        $key = implode('|', [$context['city']->getKey(), $context['lat'], $context['lng'], $context['start']->toIso8601String(),
            $context['end']?->toIso8601String(), $context['venue_id'] ?? '', $context['category_id'] ?? '', $context['event_id'] ?? '']);

        return self::$memo[$key] ??= app(EventDateChecks::class)->sameNight($context);
    }

    /** @param list<array{title: string, place: string, time: string, category: string, same_category: bool, distance_m: int, url: string}> $items */
    private static function render(array $items): string
    {
        return view('filament.partials.same-night-nearby', ['items' => $items])->render();
    }
}
