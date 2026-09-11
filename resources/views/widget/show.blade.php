{{--
    Il widget incorporabile (§11.10): la pagina che sta dentro l'`iframe` che un
    locale incolla sul proprio sito.

    Pagina **autonoma**: nessuna intestazione, nessun piè di pagina, nessuna
    navigazione. Porta però lo stesso foglio di stile del sito, quindi eredita
    i colori e il tema scuro senza una seconda palette da tenere allineata.

    Non ci sono cookie, non c'è sessione, non c'è tracciamento: chi incorpora
    questo riquadro non sta aggiungendo un osservatore alla propria pagina. Per
    la stessa ragione le date le sceglie il motore temporale (§8): un riquadro
    che mostrasse serate già passate sarebbe peggio di nessun riquadro.
--}}
@php
    $formatter = \App\Support\DateFormatter::for($venue->city);
    $app = config('app.name');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, follow">
    <title>{{ __('venues.widget.frame_title', ['venue' => $venue->name]) }}</title>
    @vite('resources/css/app.css')
</head>
<body class="bg-canvas text-ink antialiased">
    <div class="flex flex-col gap-3 p-3">
        <header class="flex items-baseline justify-between gap-2">
            <h1 class="truncate text-card text-ink">
                <a href="{{ route('venues.show', $venue) }}" target="_blank" rel="noopener" class="hover:underline">
                    {{ $venue->name }}
                </a>
            </h1>

            <a
                href="{{ route('venues.show', $venue) }}"
                target="_blank"
                rel="noopener"
                class="shrink-0 text-xs font-semibold text-brand hover:underline"
            >
                {{ __('venues.widget.all_events') }}
            </a>
        </header>

        @if ($occurrences->isEmpty())
            <p class="rounded-card bg-surface px-3 py-4 text-center text-sm text-ink-muted ring-1 ring-line">
                {{ __('venues.widget.empty') }}
            </p>
        @else
            <ul class="flex flex-col gap-2">
                @foreach ($occurrences as $occurrence)
                    @php $event = $occurrence->event; @endphp

                    <li>
                        <a
                            href="{{ \App\Support\EventUrl::occurrence($occurrence) }}"
                            target="_blank"
                            rel="noopener"
                            class="flex items-center gap-3 rounded-card bg-surface px-3 py-2.5 ring-1 ring-line transition hover:ring-line-strong"
                        >
                            <span class="ui-tag flex w-12 shrink-0 flex-col items-center rounded-card bg-brand-soft px-1 py-1 text-on-brand-soft">
                                <span class="text-eyebrow uppercase">{{ $formatter->weekdayShort($occurrence->business_date) }}</span>
                                <span class="text-card leading-none">{{ $formatter->dayNumber($occurrence->business_date) }}</span>
                            </span>

                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-semibold text-ink">{{ $event->title }}</span>

                                <span class="block truncate text-xs text-ink-muted">
                                    <time datetime="{{ $occurrence->is_all_day ? $formatter->isoDay($occurrence->business_date) : $formatter->iso($occurrence->starts_at) }}">
                                        @if ($occurrence->is_all_day)
                                            {{ $formatter->shortDate($occurrence->business_date) }} {{ __('common.separator') }} {{ __('events.badge.all_day') }}
                                        @else
                                            {{ $formatter->shortDate($occurrence->business_date) }} {{ __('common.separator') }} {{ $formatter->time($occurrence->starts_at) }}
                                        @endif
                                    </time>

                                    @if ($event->category !== null)
                                        <span aria-hidden="true">{{ __('common.separator') }}</span> {{ $event->category->name }}
                                    @endif
                                </span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif

        <p class="text-center text-[0.6875rem] text-ink-subtle">
            <a href="{{ url('/') }}" target="_blank" rel="noopener" class="hover:underline">
                {{ __('venues.widget.powered_by', ['app' => $app]) }}
            </a>
        </p>
    </div>
</body>
</html>
