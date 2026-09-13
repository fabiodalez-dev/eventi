<?php

declare(strict_types=1);

namespace App\Services\Weather;

use App\Models\EventOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

final class EventWeather
{
    /** @return array<string, mixed> */
    public function forOccurrence(EventOccurrence $occurrence): array
    {
        $event = $occurrence->event;
        $timezone = $event->city->timezone;
        $date = CarbonImmutable::instance($occurrence->starts_at)->setTimezone($timezone);
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $empty = ['available' => false, 'message' => __('weather.unavailable')];
        if (! config('weather.enabled') || $date->startOfDay()->lessThan($today) || $date->startOfDay()->greaterThan($today->addDays(15)) || ($event->content_details['attendance_mode'] ?? null) === 'online') {
            return $empty;
        }
        $venue = $occurrence->effectiveVenue();
        $lat = $venue->lat ?? ($event->custom_location['lat'] ?? null);
        $lng = $venue->lng ?? ($event->custom_location['lng'] ?? null);
        if (! is_numeric($lat) || ! is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            return $empty;
        }
        $key = 'weather:v1:'.hash('sha256', round((float) $lat, 4).':'.round((float) $lng, 4).':'.$timezone.':'.$today->format('Y-m-d'));
        $forecast = Cache::remember($key, 1800, function () use ($lat, $lng, $timezone): array {
            try {
                $key = config('weather.api_key');
                $response = Http::acceptJson()->connectTimeout(2)->timeout(5)->get($key ? 'https://customer-api.open-meteo.com/v1/forecast' : 'https://api.open-meteo.com/v1/forecast', array_filter([
                    'latitude' => round((float) $lat, 4), 'longitude' => round((float) $lng, 4),
                    'timezone' => $timezone, 'forecast_days' => 16,
                    'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,wind_speed_10m_max',
                    'apikey' => $key,
                ], static fn ($value): bool => $value !== null));

                return $response->successful() && is_array($response->json('daily')) ? $response->json('daily') : [];
            } catch (Throwable) {
                return [];
            }
        });
        $index = array_search($date->format('Y-m-d'), $forecast['time'] ?? [], true);
        if ($index === false || ! is_numeric($forecast['weather_code'][$index] ?? null)) {
            return $empty;
        }
        $code = (int) $forecast['weather_code'][$index];
        $icon = match (true) {
            $code === 0 => 'sun', $code <= 2 => 'partly-cloudy', $code === 3 => 'cloud',
            in_array($code, [45, 48], true) => 'fog', $code >= 95 => 'storm',
            in_array($code, [71, 73, 75, 77, 85, 86], true) => 'snow', default => 'rainy',
        };
        $number = static fn (string $field): ?float => is_numeric($forecast[$field][$index] ?? null) ? round((float) $forecast[$field][$index], 1) : null;

        return ['available' => true, 'date' => $date->format('Y-m-d'), 'icon' => $icon, 'description' => __('weather.'.$icon),
            'temperature_min' => $number('temperature_2m_min'), 'temperature_max' => $number('temperature_2m_max'),
            'rain_probability' => $number('precipitation_probability_max'), 'wind_speed' => $number('wind_speed_10m_max'),
            'indicative' => $date->startOfDay()->greaterThan($today->addDays(5)), 'source' => 'Open-Meteo', 'source_url' => 'https://open-meteo.com/'];
    }
}
