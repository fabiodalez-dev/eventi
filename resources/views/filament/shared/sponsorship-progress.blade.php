{{--
    L'avanzamento di un'unità di consegna: numeri, barra e la frase che li
    spiega. La usano la scheda del gestore e le statistiche.

    Variabili: `progress` (`App\Services\Sponsorship\UnitProgress`), `badge`
    (vero per mostrare lo stato sopra la barra; predefinito: solo per i
    prodotti a visualizzazioni).

    I numeri stanno nella frase («381 di 1.000 visualizzazioni, in linea: …»):
    sopra la barra ci sono solo lo stato e i giorni che restano.

    A visualizzazioni la barra è consegnate ÷ obiettivo, con una tacca dove
    dovrebbe essere oggi; a periodo è il tempo passato. Il colore segue lo
    stato, e la frase dice sempre a parole quello che il colore suggerisce.
--}}
@php
    /** @var \App\Services\Sponsorship\UnitProgress $progress */
    $badge ??= $progress->hasGoal();
    $percent = round($progress->percent(), 1);
    $expected = $progress->expectedPercent();
    $color = $progress->color();
    $fill = match ($color) {
        'success' => 'bg-success-600 dark:bg-success-400',
        'warning' => 'bg-warning-500 dark:bg-warning-400',
        'danger' => 'bg-danger-600 dark:bg-danger-400',
        'info' => 'bg-info-600 dark:bg-info-400',
        'gray' => 'bg-gray-400 dark:bg-gray-500',
        default => 'bg-primary-600 dark:bg-primary-400',
    };
    $provisional = $progress->hasGoal() && ! $progress->ended() && ! $progress->completed() && $progress->forecast->isProvisional();
    $minDays = (int) config('sponsorship.forecast_min_days', 14);
    /* I giorni che restano: solo mentre una consegna a visualizzazioni è in
       corso (a periodo la frase dice già «giorno 5 di 20»). */
    $daysLeft = $progress->hasGoal() && $progress->started() && ! $progress->ended() && ! $progress->completed() ? $progress->daysLeft() : null;
@endphp
<div class="flex min-w-0 flex-col gap-2" data-sponsorship-progress="{{ $progress->key }}" data-progress-state="{{ $progress->state?->value ?? 'period' }}">
    @if ($badge || $daysLeft !== null)
        <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
            @if ($badge)
                <x-filament::badge :color="$color" size="sm">{{ $progress->badge() }}</x-filament::badge>
            @endif
            @if ($daysLeft !== null)
                <span class="text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ trans_choice('sponsorship-panel.progress.days_left', $daysLeft, ['count' => $daysLeft]) }}</span>
            @endif
        </div>
    @endif
    <div class="relative h-2.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10" role="progressbar"
        aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) round($percent) }}"
        aria-label="{{ __('sponsorship-panel.progress.aria', ['percent' => (int) round($percent)]) }}">
        <div class="h-full rounded-full {{ $fill }}" style="width: {{ $percent }}%"></div>
        @if ($expected !== null)
            <span class="absolute inset-y-0 w-0.5 bg-gray-950/70 dark:bg-white/80" style="left: calc({{ round($expected, 1) }}% - 1px)" aria-hidden="true" data-sponsorship-expected></span>
        @endif
    </div>
    <p class="text-sm font-medium text-gray-950 dark:text-white" data-sponsorship-phrase>{{ $progress->phrase() }}</p>
    @if ($expected !== null || $provisional)
        <p class="text-xs text-gray-500 dark:text-gray-400">
            @if ($expected !== null)
                {{ __('sponsorship-panel.progress.expected_marker') }}
            @endif
            @if ($provisional)
                {{ __('sponsorship-panel.progress.provisional', ['days' => $minDays]) }}
            @endif
        </p>
    @endif
</div>
