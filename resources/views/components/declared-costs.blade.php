@props(['costs'])
@if ($costs !== null)
<section class="mt-6 border-t border-line pt-4" aria-label="{{ __('decision.costs') }}">
    <h3 class="font-bold">{{ __('decision.costs') }}</h3>
    <dl class="mt-3 space-y-2">
        @foreach ($costs['items'] as $item)
            <div class="flex justify-between gap-4"><dt>{{ $item['label'] }}</dt><dd>{{ number_format($item['cents'] / 100, 2, ',', '.') }} {{ $costs['currency'] }}</dd></div>
        @endforeach
        <div class="flex justify-between gap-4 border-t border-line pt-2 font-bold"><dt>{{ __('decision.subtotal') }}</dt><dd>{{ number_format($costs['total_cents'] / 100, 2, ',', '.') }} {{ $costs['currency'] }}</dd></div>
    </dl>
    @if (! $costs['complete'])<p class="mt-2 text-sm text-ink-muted">{{ __('decision.partial') }}</p>@endif
</section>
@endif
