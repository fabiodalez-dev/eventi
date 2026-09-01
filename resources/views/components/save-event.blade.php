{{--
    Il cuore sulla scheda di un evento (§15.3), che è l'unico posto in cui
    «salva» è ambiguo: un evento può avere dieci date.

        una sola data futura   → si salva quella, senza chiedere niente
        più date future        → si apre un selettore compatto, con
                                 «salva tutte le date» come opzione
        serie ricorrente       → in più, «Segui questo evento»: ogni nuova
                                 data generata finirà nei salvati

    Sulle card della lista questa ambiguità non esiste — una card **è** una
    data — e infatti lì il cuore salva senza chiedere.
--}}
@props([
    'event',
    'occurrences',
    'saved' => [],
    'following' => false,
])

@php
    $formatter = app(\App\Support\DateFormatter::class);
    $isSeries = $event->recurrences()->exists();
    $single = $occurrences->count() === 1 ? $occurrences->first() : null;
@endphp

@if ($occurrences->isNotEmpty())
    <section aria-labelledby="salva-evento" class="flex flex-col gap-3 bg-surface p-card">
        <h2 id="salva-evento" class="sr-only">{{ __('account.save.action') }}</h2>

        @if ($single !== null)
            <x-save-heart :occurrence="$single" :saved="isset($saved[$single->getKey()])" variant="label" />
        @else
            <details class="group">
                <summary class="inline-flex cursor-pointer items-center gap-1.5 bg-surface px-3.5 py-2 text-sm font-semibold text-ink border-2 border-line transition hover:border-accent">
                    {{ __('account.save.choose_dates') }}
                </summary>

                <ul class="mt-3 flex flex-col gap-2">
                    @foreach ($occurrences as $occurrence)
                        <li class="flex flex-wrap items-center justify-between gap-3">
                            <time
                                datetime="{{ $occurrence->is_all_day ? $formatter->isoDay($occurrence->business_date) : $formatter->iso($occurrence->starts_at) }}"
                                class="text-sm text-ink"
                            >
                                {{ $formatter->weekdayDate($occurrence->business_date) }}
                                @unless ($occurrence->is_all_day)
                                    <span class="text-ink-muted">{{ $formatter->time($occurrence->starts_at) }}</span>
                                @endunless
                            </time>

                            <x-save-heart :occurrence="$occurrence" :saved="isset($saved[$occurrence->getKey()])" />
                        </li>
                    @endforeach
                </ul>

                {{-- «Salva tutte le date»: un solo invio con tutti gli
                     identificativi, che per chi non è collegato lo script
                     traduce in altrettante voci nel `localStorage`. --}}
                <form
                    method="POST"
                    action="{{ route('account.saved.store') }}"
                    class="mt-3"
                    data-save-all
                    data-save-authenticated="{{ auth()->check() ? '1' : '0' }}"
                    data-save-ids="{{ $occurrences->pluck('id')->join(',') }}"
                >
                    @csrf

                    @foreach ($occurrences as $occurrence)
                        <input type="hidden" name="occurrence_ids[]" value="{{ $occurrence->getKey() }}">
                    @endforeach

                    <button type="submit" class="bg-brand px-3.5 py-2 text-sm font-semibold text-on-brand transition hover:bg-brand-strong">
                        {{ __('account.save.all_dates') }}
                    </button>
                </form>
            </details>
        @endif

        @if ($isSeries)
            <div class="flex flex-col gap-1">
                <x-follow-button
                    type="event"
                    :id="$event->getKey()"
                    :following="$following"
                    :label="__('account.save.follow_series')"
                />

                <p class="max-w-prose text-xs text-ink-subtle">{{ __('account.save.follow_series_hint') }}</p>
            </div>
        @endif
    </section>
@endif
