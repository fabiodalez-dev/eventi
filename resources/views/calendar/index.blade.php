{{--
    Il calendario mensile (§11.8).

    Griglia scritta a mano: quarantadue caselle non valgono i duecentocinquanta
    chilobyte di una libreria di calendari, che porterebbe con sé un proprio
    fuso orario e una propria idea di "oggi" — cioè la seconda, dopo quella di
    `EventOccurrenceQuery`.

    Alpine fa due cose sole, e nessuna delle due è necessaria: rivela i titoli
    sul telefono, dove sette colonne di testo non si leggono, e permette di
    cambiare mese con le frecce della tastiera. Senza JavaScript restano i
    conteggi, i collegamenti ai giorni e quelli ai mesi vicini.
--}}
@php
    $formatter = app(\App\Support\DateFormatter::class);
    $weekdays = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
@endphp

<x-layouts.app :meta="$meta">
    <x-slot:head>
        @vite('resources/js/calendar.js')
    </x-slot:head>

    <header class="flex flex-col gap-2">
        <h1 class="text-balance text-hero text-ink">{{ __('calendar.title') }}</h1>

        @if ($total > 0)
            <p class="text-sm text-ink-muted">
                {{ trans_choice('calendar.total', $total, ['count' => $total, 'month' => $label]) }}
            </p>
        @endif
    </header>

    <div
        class="mt-6"
        x-data="calendario({{ Illuminate\Support\Js::from(['previous' => $previous, 'next' => $next]) }})"
        @keydown.window.arrow-left="vaiAlPrecedente($event)"
        @keydown.window.arrow-right="vaiAlSuccessivo($event)"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <nav class="flex items-center gap-2" aria-label="{{ __('calendar.label', ['month' => $label]) }}">
                @if ($previous !== null)
                    <a
                        href="{{ $previous }}"
                        rel="prev"
                        class="ui-action bg-surface px-3 py-1.5 text-sm font-semibold text-ink border-2 border-line transition hover:border-accent"
                    >
                        <span aria-hidden="true">&larr;</span>
                        <span class="sr-only">{{ __('calendar.previous') }}</span>
                    </a>
                @endif

                <h2 class="font-display text-[clamp(1.25rem,1.8vw,1.75rem)] leading-none font-extrabold tracking-[-0.03em] uppercase first-letter:uppercase">{{ $label }}</h2>

                @if ($next !== null)
                    <a
                        href="{{ $next }}"
                        rel="next"
                        class="ui-action bg-surface px-3 py-1.5 text-sm font-semibold text-ink border-2 border-line transition hover:border-accent"
                    >
                        <span aria-hidden="true">&rarr;</span>
                        <span class="sr-only">{{ __('calendar.next') }}</span>
                    </a>
                @endif
            </nav>

            <div class="flex items-center gap-3">
                @if ($month->format('Y-m') !== $today->format('Y-m'))
                    <a href="{{ route('calendar.index') }}" class="text-sm font-semibold text-brand hover:underline">
                        {{ __('calendar.current') }}
                    </a>
                @endif

                {{-- Sul telefono i titoli stanno fuori dalle caselle: questo
                     pulsante li fa entrare. Compare solo con JavaScript, perché
                     senza sarebbe un interruttore che non accende niente. --}}
                <button
                    type="button"
                    x-cloak
                    x-on:click="titoli = ! titoli"
                    x-bind:aria-expanded="titoli ? 'true' : 'false'"
                    class="bg-surface px-3 py-1.5 text-sm font-semibold text-ink border-2 border-line transition hover:border-accent sm:hidden"
                >
                    <span x-show="! titoli">{{ __('calendar.day.more') }}</span>
                    <span x-show="titoli" x-cloak>{{ __('calendar.day.less') }}</span>
                </button>
            </div>
        </div>

        <div class="mt-4 grid grid-cols-7 gap-1 sm:gap-2" role="grid" aria-label="{{ __('calendar.label', ['month' => $label]) }}">
            @foreach ($weekdays as $weekday)
                <div role="columnheader" class="pb-1 text-center text-eyebrow text-ink-subtle uppercase">
                    <span aria-hidden="true">{{ __('calendar.weekdays.'.$weekday) }}</span>
                    <span class="sr-only">{{ __('calendar.weekdays_full.'.$weekday) }}</span>
                </div>
            @endforeach

            @foreach ($cells as $cell)
                @php
                    $iso = $formatter->isoDay($cell['date']);
                    $long = $formatter->weekdayDate($cell['date']);
                @endphp

                <div
                    role="gridcell"
                    @if ($cell['count'] > 0) data-calendar-day="{{ route('events.date', ['date' => $iso]) }}" data-day-label="{{ $long }}" @endif
                    @class([
                        'flex min-h-20 flex-col p-1.5 ring-1 transition sm:min-h-28 sm:p-2',
                        'bg-surface ring-line' => $cell['in_month'],
                        'bg-surface-sunken/50 ring-transparent' => ! $cell['in_month'],
                        'opacity-55' => $cell['is_past'],
                        'ring-brand' => $cell['is_today'],
                    ])
                >
                    @if ($cell['count'] > 0)
                        <a
                            href="{{ route('events.date', ['date' => $iso]) }}"
                            class="flex min-w-0 flex-col items-start gap-0.5 font-semibold text-ink hover:text-brand sm:flex-row sm:items-baseline sm:justify-between sm:gap-1"
                            aria-label="{{ __('calendar.day.link', ['date' => $long]) }} — {{ trans_choice('calendar.day.count', $cell['count'], ['count' => $cell['count']]) }}"
                        >
                            <span @class(['text-sm', 'text-ink-subtle' => ! $cell['in_month']])>
                                {{ $formatter->dayNumber($cell['date']) }}
                            </span>

                            <span class="ui-tag bg-brand-soft px-1.5 py-0.5 text-eyebrow text-on-brand-soft" aria-hidden="true">
                                {{ $cell['count'] }}
                            </span>
                        </a>

                        {{-- Da tablet in su i titoli ci sono sempre (`sm:flex`
                             vince su `hidden`); sul telefono li accende
                             l'interruttore qui sopra, che senza JavaScript non
                             compare — e senza JavaScript restano i conteggi. --}}
                        <ul class="mt-1 hidden flex-col gap-0.5 sm:flex" x-bind:class="{ 'hidden': ! titoli, 'flex': titoli }">
                            @foreach ($cell['titles'] as $title)
                                <li class="truncate text-[0.6875rem] leading-tight text-ink-muted" title="{{ $title }}">
                                    {{ $title }}
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <span @class([
                            'text-sm',
                            'text-ink-subtle' => $cell['in_month'],
                            'text-ink-subtle/60' => ! $cell['in_month'],
                        ])>
                            <span class="sr-only">{{ $long }}</span>
                            <span aria-hidden="true">{{ $formatter->dayNumber($cell['date']) }}</span>
                        </span>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    <dialog id="calendar-day-preview" aria-labelledby="calendar-day-title" class="m-auto max-h-[85dvh] w-[calc(100%-2rem)] max-w-4xl overflow-y-auto border-2 border-accent bg-canvas p-5 text-ink backdrop:bg-black/70" data-loading="{{ __('tonight.count_loading') }}" data-error="{{ __('tonight.count_error') }}">
        <form method="dialog" class="flex justify-end"><button autofocus class="min-h-12 min-w-12 px-3 font-bold">{{ __('common.actions.close') }}</button></form>
        <h2 id="calendar-day-title" class="mb-4 text-lg font-bold"></h2>
        <div data-day-results aria-live="polite"></div>
    </dialog>

    @if ($total === 0)
        <div class="mt-8">
            {{-- Non è un contenitore vuoto: è la risposta a una domanda precisa
                 ("che c'è a settembre?"), e porta dove qualcosa c'è (§8.6). --}}
            <x-empty-state
                :title="__('calendar.empty.title')"
                :description="__('calendar.empty.body')"
            >
                @if ($next !== null)
                    <a
                        href="{{ $next }}"
                        class="ui-action bg-brand px-4 py-2.5 font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] text-on-brand uppercase transition hover:bg-brand-strong"
                    >
                        {{ __('calendar.next') }}
                    </a>
                @endif

                <a
                    href="{{ route('events.index') }}"
                    class="ui-action bg-surface px-4 py-2.5 font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] text-ink uppercase border-2 border-line transition hover:border-accent"
                >
                    {{ __('events.redirects.to_all') }}
                </a>
            </x-empty-state>
        </div>
    @endif
</x-layouts.app>
