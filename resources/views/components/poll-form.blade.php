{{--
    «Proponi le date agli amici», dalla scheda di un evento con più date.

    Sta qui e non in una pagina a parte perché è qui che nasce la domanda:
    l'evento piace, le date sono tre, e la cosa che manca è mettersi d'accordo.
    Chiuso per difetto: chi guarda l'evento non deve inciampare in un modulo.
--}}
@props(['event', 'occurrences'])

@php
    $formatter = app(\App\Support\DateFormatter::class);
    $selectable = $occurrences->take(\App\Services\Community\EventPolls::MAX_OPTIONS);
    $default = now()->addWeek()->format('Y-m-d');
@endphp

@if ($selectable->count() >= \App\Services\Community\EventPolls::MIN_OPTIONS)
    <details class="border-t-2 border-line pt-5">
        <summary class="inline-flex min-h-12 cursor-pointer items-center font-display text-sm font-extrabold tracking-[0.08em] uppercase">{{ __('polls.create') }}</summary>

        @auth
            <form method="POST" action="{{ route('polls.store') }}" class="mt-4 flex flex-col gap-4">
                @csrf
                <p class="text-sm text-ink-muted">{{ __('polls.create_hint', ['min' => \App\Services\Community\EventPolls::MIN_OPTIONS, 'max' => \App\Services\Community\EventPolls::MAX_OPTIONS]) }}</p>

                <fieldset class="flex flex-col gap-2">
                    <legend class="sr-only">{{ __('polls.create') }}</legend>
                    @foreach ($selectable as $occurrence)
                        <label class="flex items-center gap-2.5 text-sm text-ink">
                            <input type="checkbox" name="dates[]" value="{{ $occurrence->getKey() }}" class="size-4 rounded border-line" @checked($loop->index < 3)>
                            {{ $formatter->dayAndTime($occurrence->business_date, $occurrence->starts_at) }}
                        </label>
                    @endforeach
                </fieldset>

                <x-field name="note" :label="__('polls.note')" maxlength="300" />
                <x-field name="closes_at" type="date" :label="__('polls.closes_at')" :value="$default" />

                <button type="submit" class="self-start bg-brand px-4 py-2.5 text-sm font-semibold text-on-brand transition hover:bg-brand-strong">{{ __('polls.submit') }}</button>
            </form>
        @else
            <p class="mt-4 text-sm text-ink-muted">
                <a class="underline underline-offset-4" href="{{ route('login', ['intended' => url()->current()]) }}">{{ __('account.nav.login') }}</a>
            </p>
        @endauth
    </details>
@endif
