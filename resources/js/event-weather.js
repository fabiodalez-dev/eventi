const shapes = {
    sun: '<circle cx="24" cy="24" r="9"/><path d="M24 3v5m0 32v5M3 24h5m32 0h5M9 9l4 4m22 22 4 4M9 39l4-4m22-22 4-4"/>',
    cloud: '<path d="M12 33a9 9 0 0 1-1-18 12 12 0 0 1 23-1 10 10 0 0 1 1 19Z"/>',
    'partly-cloudy': '<circle cx="15" cy="15" r="8"/><path d="M15 2v3M2 15h3m1-9 2 2m17-2-2 2M16 36a8 8 0 0 1-1-16 10 10 0 0 1 19-1 9 9 0 0 1 1 17Z"/>',
    rainy: '<path d="M12 28a8 8 0 0 1-1-16 11 11 0 0 1 21-1 9 9 0 0 1 1 17ZM15 35l-3 7m14-7-3 7m14-7-3 7"/>',
    snow: '<path d="M12 27a8 8 0 0 1-1-16 11 11 0 0 1 21-1 9 9 0 0 1 1 17ZM14 34v9m-4-7 8 5m-8 0 8-5m16-2v9m-4-7 8 5m-8 0 8-5"/>',
    storm: '<path d="M12 27a8 8 0 0 1-1-16 11 11 0 0 1 21-1 9 9 0 0 1 1 17ZM25 28l-7 10h8l-5 8"/>',
    fog: '<path d="M12 25a8 8 0 0 1-1-16 11 11 0 0 1 21-1 9 9 0 0 1 1 17ZM7 33h34M12 41h24"/>',
};
export async function eventWeather() {
    for (const section of document.querySelectorAll('[data-event-weather]')) {
        const status = section.querySelector('[data-weather-status]');
        try {
            const response = await fetch(section.dataset.eventWeather, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('weather');
            const { data } = await response.json();
            if (!data.available) { status.textContent = data.message || section.dataset.failure; continue; }
            section.querySelector('[data-weather-icon]').innerHTML = shapes[data.icon] || shapes.cloud;
            section.querySelector('[data-weather-description]').textContent = data.description;
            section.querySelector('[data-weather-temperature]').textContent = [data.temperature_min, data.temperature_max].filter(v => v !== null).map(v => `${v}°`).join(' / ');
            for (const [field, value, unit] of [['rain', data.rain_probability, '%'], ['wind', data.wind_speed, ' km/h']]) {
                const node = section.querySelector(`[data-weather-${field}]`);
                node.hidden = value === null;
                node.textContent = `${node.dataset.label}: ${value}${unit}`;
            }
            section.querySelector('[data-weather-indicative]').hidden = !data.indicative;
            section.querySelector('[data-weather-content]').hidden = false;
            section.hidden = false;
            status.hidden = true;
        } catch { status.textContent = section.dataset.failure; }
        finally { section.dataset.weatherLoaded = "true"; }
    }
}
