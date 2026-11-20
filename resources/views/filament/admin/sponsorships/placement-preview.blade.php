{{--
    L'anteprima della collocazione scelta nel percorso di creazione: lo schema
    del telefono con la parte sponsorizzata nel colore d'accento (lo stesso del
    pannello del gestore) e una riga che dice dove compare.

    Variabili: `placement` (valore di `SponsorshipPlacement` o null).
--}}
@php
    $case = \App\Enums\SponsorshipPlacement::tryFrom((string) ($placement ?? ''));
@endphp
@if ($case !== null)
    <div class="flex items-center gap-4 rounded-xl border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-white/5" data-placement-preview="{{ $case->value }}">
        @include('filament.shared.sponsorship-format-preview', ['placement' => $case->value])
        <div class="min-w-0 flex-1">
            <p class="font-semibold text-gray-950 dark:text-white">{{ $case->label() }}</p>
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('promotions.formats.'.$case->value) }}</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ trans_choice('promotions.formats.rotation', $case->limit(), ['count' => $case->limit()]) }}</p>
        </div>
    </div>
@endif
