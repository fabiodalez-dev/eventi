{{--
    La procedura «Stasera»: una domanda per schermata.

    In cima l'avanzamento («Passo 2 di 5» e una barra a segmenti), poi la
    domanda come unico titolo della pagina e il suo aiuto. Il modulo con le
    risposte, il riepilogo, i primi risultati e i comandi sta in
    `tonight/question`, che il controller prepara una volta sola: la stessa
    vista risponde anche alle richieste di anteprima mentre si sceglie.
--}}
@php
    $index = array_search($question, $questions, true);
    $total = count($questions) - 1;
    $help = match ($question) {
        'when' => __('tonight.when_help'),
        'municipality' => __('tonight.municipality_help'),
        'district' => __('tonight.district_help'),
        'budget' => __('tonight.budget_help'),
        default => __('tonight.categories_help'),
    };
@endphp
<x-layouts.app service :meta="$meta">
    <div class="flex min-w-0 flex-col gap-6" data-tonight>
        <div class="tonight-progress" data-tonight-progress>
            <p class="m-0 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <span class="text-eyebrow text-accent uppercase" data-tonight-step>{{ __('tonight.progress', ['current' => $index + 1, 'total' => $total]) }}</span>
                <span class="text-sm text-ink-muted">{{ __('tonight.context', ['city' => $city->name]) }}</span>
            </p>
            <ol class="tonight-progress__bar" aria-label="{{ __('tonight.progress_label') }}">
                @foreach (array_slice($questions, 0, $total) as $position => $step)
                    <li @class(['tonight-progress__step', 'is-done' => $position < $index, 'is-current' => $position === $index]) @if ($position === $index) aria-current="step" @endif>
                        <span class="sr-only">{{ __('tonight.'.$step) }}</span>
                    </li>
                @endforeach
            </ol>
        </div>

        <x-service-page :title="__('tonight.'.$question)" :subtitle="$help">
            {!! $form !!}
        </x-service-page>
    </div>
</x-layouts.app>
