<?php

declare(strict_types=1);

use App\DTOs\EventFilters;
use Illuminate\Support\Facades\Blade;

function mapFramingConfiguration(array $markers, ?array $center = null): array
{
    $city = testCity();
    $city->bounds = ['min_lng' => 11.8, 'min_lat' => 45.3, 'max_lng' => 11.9, 'max_lat' => 45.5];
    $html = Blade::render(
        '<x-events-map :city="$city" :filters="$filters" :payload="$payload" :center="$center" />',
        ['city' => $city, 'filters' => new EventFilters, 'payload' => ['markers' => $markers, 'categories' => [], 'truncated' => false], 'center' => $center],
    );
    preg_match('/data-map-config>(.*?)<\/script>/s', $html, $matches);

    return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
}

it('inquadra tutti i risultati anche fuori dai confini della città', function (): void {
    $config = mapFramingConfiguration([[1, 11.1, 45.1, 0, 1, 'A'], [2, 12.2, 45.9, 0, 1, 'B']]);
    expect($config['bounds'])->toBe(['min_lng' => 11.1, 'min_lat' => 45.1, 'max_lng' => 12.2, 'max_lat' => 45.9]);
});

it('centra anche un solo risultato senza usare il centro cittadino', function (): void {
    $config = mapFramingConfiguration([[1, 11.1, 45.1, 0, 1, 'Vintage']]);
    expect($config['bounds']['min_lng'])->toBe(11.1)
        ->and($config['bounds']['max_lng'])->toBe(11.1);
});

it('conserva il centro esplicito delle schede evento e locale', function (): void {
    $config = mapFramingConfiguration([[1, 11.1, 45.1, 0, 1, 'A']], [11.1, 45.1]);
    expect($config['bounds'])->toBeNull()->and($config['center'])->toBe([11.1, 45.1]);
});

it('usa la città solo quando non ci sono risultati', function (): void {
    expect(mapFramingConfiguration([])['bounds']['min_lng'])->toBe(11.8);
});
