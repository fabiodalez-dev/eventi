@props(['rows', 'name', 'metric', 'label', 'secondary' => null])
@php $maximum = max(1, collect($rows)->max($metric) ?? 0); @endphp
<div class="ad-bars" data-analytics-bars aria-label="{{ $label }}">
    @forelse ($rows as $row)
        <div class="ad-bar-row">
            <div><span>{{ $row[$name] }}</span><strong>{{ number_format($row[$metric] ?? 0, 0, ',', '.') }}@if ($secondary !== null)<small>{{ $secondary['label'] }}: {{ number_format($row[$secondary['key']] ?? 0, 0, ',', '.') }}</small>@endif</strong></div>
            <svg viewBox="0 0 1000 8" preserveAspectRatio="none" aria-hidden="true"><rect width="1000" height="8" class="ad-bar-track"/><rect width="{{ 1000 * ($row[$metric] ?? 0) / $maximum }}" height="8" class="ad-bar-value"/></svg>
        </div>
    @empty
        <p class="ad-muted">{{ __('analytics-dashboard.empty_chart') }}</p>
    @endforelse
</div>
