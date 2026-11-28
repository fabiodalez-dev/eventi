{{--
    La colonna «Avanzamento» degli elenchi di campagne e abilitazioni: barra,
    «consegnate di obiettivo» e lo stato; per le unità a periodo solo le
    visualizzazioni.

    Variabili: `progress` (`UnitProgress` o null).
--}}
@php
    /** @var \App\Services\Sponsorship\Editorial\UnitProgress|null $progress */
    $progress ??= null;
    $percent = $progress?->percent();
    $expected = $progress?->expectedPercent();
@endphp
<div class="flex min-w-36 flex-col gap-1 px-3 py-2" data-progress="{{ $progress?->stateKey() ?? 'none' }}">
    @if ($progress === null)
        <span class="text-sm text-gray-500 dark:text-gray-400">—</span>
    @elseif (! $progress->hasGoal())
        <span class="text-sm text-gray-950 dark:text-white">{{ trans_choice('sponsorships.progress.views_only', $progress->delivered, ['count' => number_format($progress->delivered, 0, ',', '.')]) }}</span>
        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $progress->stateLabel() }}</span>
    @else
        <span class="text-sm font-semibold text-gray-950 tabular-nums dark:text-white">{{ __('sponsorships.progress.of_goal', ['delivered' => number_format($progress->delivered, 0, ',', '.'), 'goal' => number_format((int) $progress->goal, 0, ',', '.')]) }}</span>
        <span class="relative block h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) round($percent ?? 0) }}" aria-label="{{ __('sponsorships.progress.column') }}">
            <span @class([
                'block h-full rounded-full',
                'bg-success-500 dark:bg-success-400' => $progress->color() === 'success',
                'bg-info-500 dark:bg-info-400' => $progress->color() === 'info',
                'bg-warning-500 dark:bg-warning-400' => $progress->color() === 'warning',
                'bg-danger-500 dark:bg-danger-400' => $progress->color() === 'danger',
                'bg-gray-400 dark:bg-gray-500' => $progress->color() === 'gray',
            ]) style="width: {{ $percent }}%"></span>
            @if ($expected !== null && $expected > 0 && $expected < 100)
                <span class="absolute inset-y-0 w-0.5 bg-gray-950/60 dark:bg-white/70" style="left: {{ $expected }}%" aria-hidden="true"></span>
            @endif
        </span>
        <x-filament::badge :color="$progress->color()" size="sm" class="self-start">{{ $progress->stateLabel() }}</x-filament::badge>
    @endif
</div>
