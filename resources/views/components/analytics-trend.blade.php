@props(['points', 'metrics', 'label'])
@php
    $max = max(1, collect($points)->flatMap(fn ($point) => array_map(fn ($key) => $point[$key] ?? 0, array_keys($metrics)))->max() ?? 0);
    $ceiling = max(5, (int) ceil($max / 5) * 5);
    $path = fn ($key) => implode(' ', array_map(fn ($point, $i) => round(24 + 352 * $i / max(1, count($points) - 1), 2).','.round(180 - 155 * ($point[$key] ?? 0) / $ceiling, 2), $points, array_keys($points)));
@endphp
<div class="ad-trend" data-analytics-trend>
    <div class="ad-chart-legend">
        @foreach ($metrics as $key => $name)<span class="ad-series-{{ $loop->index }}">{{ $name }}</span>@endforeach
    </div>
    <svg viewBox="0 0 400 210" role="img" aria-label="{{ $label }}" preserveAspectRatio="none">
        @foreach ([0, 0.5, 1] as $step)
            <line x1="24" x2="380" y1="{{ 180 - 155 * $step }}" y2="{{ 180 - 155 * $step }}" class="ad-gridline" />
            <text x="24" y="{{ 174 - 155 * $step }}" class="ad-axis-label">{{ number_format($ceiling * $step, 0, ',', '.') }}</text>
        @endforeach
        @foreach ($metrics as $key => $name)
            <polyline points="{{ $path($key) }}" class="ad-line ad-line-{{ $loop->index }}" />
            @foreach ($points as $i => $point)
                <circle cx="{{ 24 + 352 * $i / max(1, count($points) - 1) }}" cy="{{ 180 - 155 * ($point[$key] ?? 0) / $ceiling }}" r="4" class="ad-point ad-point-{{ $loop->parent->index }}" tabindex="0">
                    <title>{{ $point['date'] }} · {{ $name }}: {{ $point[$key] ?? 0 }}</title>
                </circle>
            @endforeach
        @endforeach
    </svg>
    <div class="ad-chart-dates"><span>{{ $points[0]['date'] ?? '' }}</span><span>{{ $points[array_key_last($points)]['date'] ?? '' }}</span></div>
</div>
