@props(['occurrence'])
<section hidden class="my-6 border border-line p-5" data-event-weather="{{ route('api.v1.occurrences.weather', ['occurrence' => $occurrence->id]) }}" data-failure="{{ __('weather.failure') }}">
    <h2 class="mb-3 text-xl font-bold">{{ __('weather.title') }} · {{ $occurrence->starts_at->copy()->timezone($occurrence->event->city->timezone)->format('d/m/Y') }}</h2>
    <p data-weather-status role="status">{{ __('weather.loading') }}</p>
    <div data-weather-content hidden>
        <div class="flex items-center gap-4"><svg data-weather-icon class="h-14 w-14 shrink-0 text-accent" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"></svg><div><p data-weather-description class="font-semibold"></p><p data-weather-temperature class="text-2xl font-bold"></p></div></div>
        <p class="mt-3 text-sm">{{ __('weather.day') }}</p>
        <div class="mt-3 flex flex-wrap gap-4"><p data-weather-rain data-label="{{ __('weather.rain') }}"></p><p data-weather-wind data-label="{{ __('weather.wind') }}"></p></div>
        <p data-weather-indicative hidden class="mt-3 text-sm text-ink-muted">{{ __('weather.indicative') }}</p>
        <a href="https://open-meteo.com/" class="mt-3 inline-block text-sm underline" rel="noopener">Open-Meteo · CC BY 4.0</a>
    </div>
</section>
