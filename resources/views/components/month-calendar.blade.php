{{--
    La griglia di un mese (§11.8): la usano `/calendario` e la pagina iniziale.

    È una tabella vera, settimane per righe e giorni per colonne: è ciò che un
    calendario è, e uno screen reader la legge come tale («martedì, 12»).

    - `compact`: caselle con il numero del giorno e il conteggio, alte almeno
      44 px, ogni giorno con eventi è un collegamento alla sua lista. È la
      variante della pagina iniziale.
    - completa: in più la riga con i mesi vicini e, da tablet in su, i primi
      titoli di ogni giorno; sul telefono li mostra il pulsante «Mostra i
      titoli». Il comportamento sta in `resources/js/calendar.js`, senza
      Alpine: la CSP del sito non permette di valutare espressioni scritte
      negli attributi, e il vecchio `x-data` restava lettera morta.

    Senza JavaScript restano i conteggi, i collegamenti ai giorni e quelli ai
    mesi vicini.
--}}
@props([
    /* list<array{date, in_month, is_today, is_past, count, titles}> da MonthCalendar */
    'cells',
    'label',
    'previous' => null,
    'next' => null,
    /* Il collegamento «Torna a questo mese», quando si guarda un altro mese. */
    'current' => null,
    'compact' => false,
    /* Il giorno apre l'anteprima nella finestra di `/calendario`. */
    'preview' => false,
])

@php
    $formatter = app(\App\Support\DateFormatter::class);
    $weekdays = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    $weeks = array_chunk($cells, 7);
@endphp

<div
    {{ $attributes->class(['month-calendar group/calendar', 'month-calendar--compact' => $compact]) }}
    data-month-calendar
    @if (! $compact)
        data-calendar-keyboard
        @if ($previous !== null) data-previous="{{ $previous }}" @endif
        @if ($next !== null) data-next="{{ $next }}" @endif
    @endif
>
    @unless ($compact)
        <div class="flex flex-wrap items-center justify-between gap-3">
            <nav class="flex items-center gap-2" aria-label="{{ __('calendar.label', ['month' => $label]) }}">
                @if ($previous !== null)
                    <a href="{{ $previous }}" rel="prev" class="month-calendar__step" aria-label="{{ __('calendar.previous') }}" title="{{ __('calendar.previous') }}">
                        <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="square"><path d="m15 18-6-6 6-6"/></svg>
                    </a>
                @endif

                <h2 class="month-calendar__label font-display text-[clamp(1.25rem,1.8vw,1.75rem)] leading-none font-extrabold tracking-[-0.03em] uppercase">{{ $label }}</h2>

                @if ($next !== null)
                    <a href="{{ $next }}" rel="next" class="month-calendar__step" aria-label="{{ __('calendar.next') }}" title="{{ __('calendar.next') }}">
                        <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="square"><path d="m9 18 6-6-6-6"/></svg>
                    </a>
                @endif
            </nav>

            <div class="flex flex-wrap items-center gap-2">
                @if ($current !== null)
                    <x-button :href="$current" variant="ghost" size="sm">{{ __('calendar.current') }}</x-button>
                @endif

                {{-- Sul telefono i titoli stanno fuori dalle caselle: questo
                     pulsante li fa entrare. Nasce nascosto e lo mostra lo
                     script, perché senza sarebbe un interruttore che non
                     accende niente. Un solo testo, che lo script cambia. --}}
                <button
                    type="button"
                    hidden
                    data-calendar-titles
                    aria-expanded="false"
                    aria-controls="{{ $id = 'calendario-'.\Illuminate\Support\Str::random(6) }}"
                    data-label-more="{{ __('calendar.day.more') }}"
                    data-label-less="{{ __('calendar.day.less') }}"
                    class="month-calendar__titles-toggle sm:!hidden"
                >{{ __('calendar.day.more') }}</button>
            </div>
        </div>
    @endunless

    <table
        @isset($id) id="{{ $id }}" @endisset
        @class(['month-calendar__table w-full table-fixed border-separate', 'mt-4' => ! $compact])
        aria-label="{{ __('calendar.label', ['month' => $label]) }}"
    >
        <thead>
            <tr>
                @foreach ($weekdays as $weekday)
                    <th scope="col" abbr="{{ __('calendar.weekdays_full.'.$weekday) }}" class="month-calendar__weekday pb-1 text-center text-eyebrow font-semibold text-ink-subtle uppercase">
                        {{ __('calendar.weekdays.'.$weekday) }}
                    </th>
                @endforeach
            </tr>
        </thead>

        <tbody>
            @foreach ($weeks as $week)
                <tr>
                    @foreach ($week as $cell)
                        @php
                            $iso = $formatter->isoDay($cell['date']);
                            $long = $formatter->weekdayDate($cell['date']);
                            $count = trans_choice('calendar.day.count', $cell['count'], ['count' => $cell['count']]);
                        @endphp

                        <td
                            @class([
                                'month-calendar__day align-top',
                                'is-outside' => ! $cell['in_month'],
                                'is-past' => $cell['is_past'],
                                'is-today' => $cell['is_today'],
                                'has-events' => $cell['count'] > 0,
                            ])
                            @if ($cell['is_today']) aria-current="date" @endif
                            @if ($preview && $cell['count'] > 0) data-calendar-day="{{ route('events.date', ['date' => $iso]) }}" data-day-label="{{ $long }}" @endif
                        >
                            @if ($cell['count'] > 0)
                                <a
                                    href="{{ route('events.date', ['date' => $iso]) }}"
                                    class="month-calendar__link"
                                    aria-label="{{ __('calendar.day.link', ['date' => $long]) }} — {{ $count }}"
                                >
                                    <span class="month-calendar__number">{{ $formatter->dayNumber($cell['date']) }}</span>
                                    <span class="month-calendar__count" aria-hidden="true">{{ $cell['count'] }}</span>
                                </a>

                                @unless ($compact)
                                    {{-- Da tablet in su i titoli ci sono sempre;
                                         sul telefono li accende il pulsante
                                         qui sopra. --}}
                                    <ul class="month-calendar__titles mt-1 hidden flex-col gap-0.5 sm:flex group-data-[titles=open]/calendar:flex">
                                        @foreach ($cell['titles'] as $title)
                                            <li class="truncate text-[0.6875rem] leading-tight text-ink-muted" title="{{ $title }}">{{ $title }}</li>
                                        @endforeach
                                    </ul>
                                @endunless
                            @else
                                <span class="month-calendar__empty">
                                    <span class="sr-only">{{ $long }}</span>
                                    <span class="month-calendar__number" aria-hidden="true">{{ $formatter->dayNumber($cell['date']) }}</span>
                                </span>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
