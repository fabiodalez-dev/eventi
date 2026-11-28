{{--
    L'avanzamento di un'unità nella scheda di modifica (campagna singola o
    abilitazione): i numeri principali, la consegna giorno per giorno contro
    l'atteso e, per le abilitazioni, gli eventi con i loro numeri.

    Variabili: `progress` (`UnitProgress` o null), `daily` (righe di
    `SponsorshipProgress::daily`), `events` (righe di `grantEvents`, o []),
    `quotaMax` (float), `minDays` (int), `provisional` (bool).
--}}
@php
    use App\Filament\Support\PanelDate;

    /** @var \App\Services\Sponsorship\Editorial\UnitProgress|null $progress */
    $progress ??= null;
    $daily ??= [];
    $events ??= [];
    $n = static fn (int|float $value): string => number_format((float) $value, 0, ',', '.');
@endphp
<div class="flex flex-col gap-6" data-progress-detail="{{ $progress?->stateKey() ?? 'none' }}">
    @if ($progress === null)
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('sponsorships.progress.no_unit') }}</p>
    @else
        <div class="flex flex-col gap-2">
            <div class="flex flex-wrap items-center gap-2">
                <x-filament::badge :color="$progress->color()">{{ $progress->stateLabel() }}</x-filament::badge>
                <span class="text-sm text-gray-600 dark:text-gray-300">{{ __('sponsorships.progress.true_end', ['date' => PanelDate::event($progress->end, $progress->timezone)]) }}</span>
            </div>
            @if ($progress->hasGoal())
                <p class="text-base text-gray-950 dark:text-white">{{ __('sponsorships.progress.sentence', ['delivered' => $n($progress->delivered), 'goal' => $n((int) $progress->goal), 'state' => mb_strtolower($progress->stateLabel())]) }}</p>
                <span class="relative block h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) round($progress->percent() ?? 0) }}">
                    <span class="block h-full rounded-full bg-primary-500 dark:bg-primary-400" style="width: {{ $progress->percent() }}%"></span>
                    @if (($progress->expectedPercent() ?? 0) > 0 && ($progress->expectedPercent() ?? 100) < 100)
                        <span class="absolute inset-y-0 w-0.5 bg-gray-950/60 dark:bg-white/70" style="left: {{ $progress->expectedPercent() }}%" aria-hidden="true"></span>
                    @endif
                </span>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ trans_choice('sponsorships.progress.bar_legend', (int) round($progress->expected), ['expected' => $n($progress->expected)]) }}</p>
            @else
                <p class="text-base text-gray-950 dark:text-white">{{ trans_choice('sponsorships.progress.period_sentence', $progress->delivered, ['count' => $n($progress->delivered)]) }}</p>
            @endif
        </div>

        <x-pannelli::stat-cards :label="__('sponsorships.progress.heading')" :items="array_values(array_filter([
            ['key' => 'today', 'label' => __('sponsorships.progress.delivered_today'), 'value' => $progress->deliveredToday, 'hint' => __('sponsorships.progress.delivered_today_hint')],
            $progress->hasGoal() ? ['key' => 'quota', 'label' => __('sponsorships.progress.required_quota'), 'value' => $progress->quotaLabel($quotaMax), 'hint' => __('sponsorships.progress.required_quota_hint')] : null,
            ['key' => 'days', 'label' => __('sponsorships.progress.days_left'), 'value' => $progress->daysLeft, 'hint' => __('sponsorships.progress.days_left_hint')],
        ]))" />

        @if ($progress->hasGoal() && ($provisional ?? false))
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('sponsorships.progress.provisional', ['days' => $minDays]) }}</p>
        @endif

        @if ($progress->hasGoal())
            <section class="flex flex-col gap-2" aria-labelledby="progress-daily-heading" data-progress-daily>
                <h3 id="progress-daily-heading" class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('sponsorships.progress.daily') }}</h3>
                @if ($daily === [])
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('sponsorships.progress.daily_empty') }}</p>
                @else
                    <ul class="flex flex-col divide-y divide-gray-100 dark:divide-white/10" role="list">
                        @foreach (array_reverse($daily) as $row)
                            <li class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5 py-1.5 text-sm">
                                <span class="text-gray-950 dark:text-white">{{ PanelDate::day($row['day'], $progress->timezone) }}</span>
                                <span class="tabular-nums text-gray-700 dark:text-gray-300">{{ __('sponsorships.progress.daily_row', ['delivered' => $n($row['delivered']), 'expected' => $n($row['expected'])]) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        @if ($events !== [])
            <section class="flex flex-col gap-2" aria-labelledby="progress-events-heading" data-progress-events>
                <h3 id="progress-events-heading" class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('sponsorships.progress.events') }}</h3>
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('sponsorships.progress.events_lead') }}</p>
                <ul class="flex flex-col gap-3" role="list">
                    @foreach ($events as $row)
                        <li data-progress-event="{{ $row['row']->getKey() }}">
                            @if ($row['event'] !== null)
                                <x-pannelli::event-row :event="$row['event']" :when="$row['next']" :venue="$row['event']->venue?->name" size="sm">
                                    <p class="mt-1 text-xs text-gray-600 tabular-nums dark:text-gray-300">
                                        {{ __('sponsorships.progress.event_numbers', ['views' => $n($row['impressions']), 'clicks' => $n($row['clicks'])]) }}
                                        · {{ $row['share'] === null ? __('sponsorships.progress.event_no_share') : __('sponsorships.progress.event_share', ['share' => $n($row['share'] * 100)]) }}
                                    </p>
                                </x-pannelli::event-row>
                            @else
                                <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('sponsorships.analytics.event_gone') }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    @endif
</div>
