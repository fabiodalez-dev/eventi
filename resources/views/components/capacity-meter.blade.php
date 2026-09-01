{{--
    Capienza e posti rimasti di una data.

    La barra si disegna **solo** quando esiste un totale credibile: una
    percentuale calcolata su una capienza inventata è peggio di nessuna
    percentuale. Il conto dei posti rimasti, invece, vale da solo.
--}}
@props([
    'capacity',
    'compact' => false,
])

@php
    $percent = $capacity?->percentSold();
@endphp

@if ($capacity !== null)
    <div {{ $attributes->class(['flex flex-col gap-1.5']) }}>
        @if ($percent !== null)
            <div
                class="h-1 w-full overflow-hidden bg-surface-sunken"
                role="progressbar"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-valuenow="{{ $percent }}"
                aria-label="{{ __('events.capacity.progress_label') }}"
            >
                <div class="h-full bg-brand" style="width: {{ $percent }}%"></div>
            </div>
        @endif

        <p @class(['flex flex-wrap items-baseline justify-between gap-2', 'text-xs' => $compact, 'text-sm' => ! $compact])>
            @if ($capacity->total !== null)
                <span class="text-ink-subtle">
                    {{ __('events.capacity.total', ['count' => \Illuminate\Support\Number::format($capacity->total, locale: app()->getLocale())]) }}
                </span>
            @endif

            <span class="font-semibold text-ink">
                @if ($capacity->isSoldOut())
                    {{ __('events.capacity.sold_out') }}
                @else
                    {{ trans_choice('events.capacity.left', $capacity->left, ['count' => $capacity->left]) }}@if ($percent !== null)<span class="font-normal text-ink-subtle"> {{ __('common.separator') }} {{ __('events.capacity.sold', ['percent' => $percent]) }}</span>@endif
                @endif
            </span>
        </p>
    </div>
@endif
