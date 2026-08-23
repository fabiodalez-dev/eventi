{{--
    Lo scroller dei prossimi giorni con l'indicatore di densità (§11.2, punto 5).

    La densità è un numero da 0 a 3 calcolato dal controller sui conteggi reali:
    la barretta dice a colpo d'occhio quale sera è piena e quale è vuota, che è
    l'informazione per cui si guarda una fila di giorni.

    Ogni giorno è un link a `/eventi/{yyyy-mm-dd}`: funziona senza JavaScript e
    si può copiare.
--}}
@props([
    'days',
    'label',
])

@php
    $formatter = app(\App\Support\DateFormatter::class);
@endphp

<div {{ $attributes->class(['scroll-row gap-2 pb-1']) }} role="list" aria-label="{{ $label }}">
    @foreach ($days as $day)
        <a
            role="listitem"
            href="{{ route('events.date', ['date' => $formatter->isoDay($day['date'])]) }}"
            @class([
                'flex w-[4.5rem] flex-col items-center gap-1.5 rounded-card px-2 py-2.5 text-center ring-1 transition',
                'bg-surface ring-line hover:ring-line-strong' => $day['count'] > 0,
                'bg-surface-sunken ring-line opacity-60' => $day['count'] === 0,
            ])
        >
            <span class="text-eyebrow text-ink-subtle uppercase">{{ $formatter->weekdayShort($day['date']) }}</span>
            <span class="text-card text-ink">{{ $formatter->dayNumber($day['date']) }}</span>

            <span class="flex items-end gap-0.5" aria-hidden="true">
                @foreach ([1, 2, 3] as $step)
                    <span @class([
                        'w-1 rounded-pill',
                        'h-1.5' => $step === 1,
                        'h-2.5' => $step === 2,
                        'h-3.5' => $step === 3,
                        'bg-brand' => $day['density'] >= $step,
                        'bg-line' => $day['density'] < $step,
                    ])></span>
                @endforeach
            </span>

            <span class="sr-only">{{ trans_choice('events.count', $day['count'], ['count' => $day['count']]) }}</span>
        </a>
    @endforeach
</div>
