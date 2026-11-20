<x-filament-panels::page>
    @php
        $report = $this->report();
        $tz = $this->timezone();
        $muted = 'text-sm text-gray-600 dark:text-gray-400';
        $number = fn (int $n): string => number_format($n, 0, ',', '.');
        $rateReady = $report['impressions'] >= $report['min_ctr'];
        $rate = $rateReady ? number_format(100 * $report['clicks'] / $report['impressions'], 2, ',', '.').'%' : __('sponsorship-panel.analytics.rate_pending', ['min' => $number($report['min_ctr'])]);
    @endphp

    <div class="flex min-w-0 flex-col gap-6" data-sponsorship-analytics>
        <x-filament::section>
            {{ $this->form }}
            <p class="mt-3 {{ $muted }}">{{ __('pannelli-social.sponsorship.window', ['period' => \App\Filament\Support\PanelDate::period($report['from'], $report['to'])]) }}</p>
        </x-filament::section>

        @if ($report['economics'] !== null)
            <x-filament::section :heading="__('decision.spend')" :description="__('decision.cost_definition')">
                <dl class="flex flex-wrap gap-x-8 gap-y-3">
                    @foreach (['spend_cents' => 'spend', 'per_booking_cents' => 'cost_per_booking', 'per_attendance_cents' => 'cost_per_attendance'] as $key => $label)
                        <div>
                            <dt class="{{ $muted }}">{{ __('decision.'.$label) }}</dt>
                            <dd class="text-lg font-semibold tabular-nums text-gray-950 dark:text-white">{{ $report['economics'][$key] === null ? __('decision.not_available') : number_format($report['economics'][$key] / 100, 2, ',', '.').' '.$report['economics']['currency'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-filament::section>
        @endif

        @if ($report['progress'] !== null)
            <x-filament::section :heading="__('sponsorship-panel.analytics.progress_title')" :description="__($report['grant_unit'] ? 'sponsorship-panel.analytics.progress_lead_grant' : 'sponsorship-panel.analytics.progress_lead_single')" data-sponsorship-unit-progress>
                @include('filament.shared.sponsorship-progress', ['progress' => $report['progress'], 'badge' => true])

                @if (($report['delivery']['days'] ?? []) !== [])
                    @php
                        $giorni = $report['delivery']['days'];
                        $tetto = max(1, ...array_column($giorni, 'delivered'), ...array_column($giorni, 'expected'));
                        $n = count($giorni);
                        $punti = fn (string $chiave): string => collect($giorni)->values()->map(fn ($g, $i) => round(10 + $i * 580 / max(1, $n - 1), 1).','.round(150 - $g[$chiave] * 130 / $tetto, 1))->implode(' ');
                    @endphp
                    <div class="mt-6 flex flex-col gap-2" data-sponsorship-delivery>
                        <p class="text-sm font-medium text-gray-950 dark:text-white">{{ __('sponsorship-panel.analytics.delivery_title') }}</p>
                        <p class="{{ $muted }}">{{ __('sponsorship-panel.analytics.delivery_lead') }}</p>
                        <svg viewBox="0 0 600 160" class="block h-auto max-h-56 w-full text-primary-600 dark:text-primary-400" role="img"
                            aria-label="{{ __('sponsorship-panel.analytics.delivery_label', ['date' => end($giorni)['date'], 'delivered' => $number($report['delivery']['delivered']), 'expected' => $number($report['delivery']['expected'])]) }}">
                            <line x1="10" y1="150" x2="590" y2="150" stroke="currentColor" opacity=".25" />
                            <polyline points="{{ $punti('expected') }}" fill="none" stroke="currentColor" stroke-width="2" stroke-dasharray="6 5" opacity=".55" stroke-linejoin="round" stroke-linecap="round" />
                            <polyline points="{{ $punti('delivered') }}" fill="none" stroke="currentColor" stroke-width="3" stroke-linejoin="round" stroke-linecap="round" />
                        </svg>
                        <div class="flex flex-wrap justify-between gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                            <span>{{ $giorni[0]['day'] }}</span>
                            <span>{{ __('sponsorship-panel.analytics.delivered') }}: {{ $number($report['delivery']['delivered']) }} · {{ __('sponsorship-panel.analytics.expected') }}: {{ $number($report['delivery']['expected']) }}</span>
                            <span>{{ end($giorni)['day'] }}</span>
                        </div>
                    </div>
                @endif
            </x-filament::section>
        @endif

        <x-filament::section :heading="__('sponsorships.analytics.totals')">
            <dl class="flex flex-wrap gap-x-10 gap-y-4" data-sponsorship-totals>
                <div><dt class="{{ $muted }}">{{ __('sponsorships.analytics.impressions') }}</dt><dd class="text-3xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $number($report['impressions']) }}</dd></div>
                <div><dt class="{{ $muted }}">{{ __('sponsorships.analytics.clicks') }}</dt><dd class="text-3xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $number($report['clicks']) }}</dd></div>
                <div><dt class="{{ $muted }}">{{ __('sponsorships.analytics.rate') }}</dt><dd @class(['font-semibold tabular-nums', 'text-3xl text-gray-950 dark:text-white' => $rateReady, 'pt-2 text-lg text-gray-500 dark:text-gray-400' => ! $rateReady]) data-sponsorship-rate>{{ $rate }}</dd></div>
            </dl>
            <p class="mt-4 text-sm text-gray-700 dark:text-gray-300" data-sponsorship-explain>
                @if ($report['impressions'] === 0)
                    {{ __('pannelli-social.sponsorship.explain_empty') }}
                @elseif ($report['clicks'] === 0)
                    {{ __('pannelli-social.sponsorship.explain_none', ['views' => $number($report['impressions'])]) }}
                @else
                    {{ trans_choice('pannelli-social.sponsorship.explain', $report['clicks'], ['views' => $number($report['impressions']), 'clicks' => $number($report['clicks'])]) }}
                    @if ($rateReady)
                        {{ __('pannelli-social.sponsorship.explain_rate', ['every' => $number(max(1, (int) round($report['impressions'] / $report['clicks'])))]) }}
                    @endif
                @endif
            </p>
            @unless ($rateReady)
                <p class="mt-2 {{ $muted }}" data-sponsorship-rate-threshold>{{ __('sponsorship-panel.analytics.rate_threshold', ['min' => $number($report['min_ctr'])]) }}</p>
            @endunless
        </x-filament::section>

        @foreach (['impressions' => 'chart_impressions', 'clicks' => 'chart_clicks'] as $metric => $label)
            @php
                $peak = (int) $report['series']->max($metric);
                $max = max(1, $peak);
                $count = $report['series']->count();
                $points = $report['series']->values()->map(fn ($row, $i) => round(10 + $i * 580 / max(1, $count - 1), 1).','.round(150 - $row[$metric] * 130 / $max, 1))->implode(' ');
            @endphp
            <x-filament::section :heading="__('sponsorships.analytics.'.$label)">
                @if ($peak === 0)
                    <p class="{{ $muted }}">{{ __('sponsorships.analytics.chart_empty') }}</p>
                @else
                    <svg viewBox="0 0 600 160" class="block h-auto max-h-56 w-full text-primary-600 dark:text-primary-400" role="img" aria-label="{{ __('sponsorships.analytics.chart_label', ['label' => __('sponsorships.analytics.'.$label), 'max' => $peak]) }}">
                        <line x1="10" y1="150" x2="590" y2="150" stroke="currentColor" opacity=".25" />
                        <polyline points="{{ $points }}" fill="none" stroke="currentColor" stroke-width="3" stroke-linejoin="round" stroke-linecap="round" />
                    </svg>
                    <div class="mt-2 flex justify-between gap-4 text-xs text-gray-500 dark:text-gray-400">
                        <span>{{ $report['series']->first()['day'] }}</span>
                        <span>{{ __('sponsorships.analytics.peak', ['max' => $number($peak)]) }}</span>
                        <span>{{ $report['series']->last()['day'] }}</span>
                    </div>
                @endif
            </x-filament::section>
        @endforeach

        <x-filament::section :heading="__('sponsorships.analytics.daily')" collapsible collapsed>
            <ul class="flex flex-col divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($report['series']->reverse() as $row)
                    <li class="flex items-center justify-between gap-4 py-2 text-sm">
                        <span class="text-gray-950 dark:text-white">{{ $row['date'] }}</span>
                        <span class="tabular-nums text-gray-600 dark:text-gray-400">{{ __('sponsorships.analytics.daily_row', ['views' => $number($row['impressions']), 'clicks' => $number($row['clicks'])]) }}</span>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>

        <x-filament::section :heading="__('sponsorships.analytics.register')" :description="__('sponsorships.analytics.register_lead')">
            @if ($report['records']->isEmpty())
                <p class="{{ $muted }}">{{ __('sponsorships.analytics.register_empty') }}</p>
            @else
                <ul class="flex flex-col divide-y divide-gray-200 dark:divide-white/10" data-sponsorship-clicks>
                    @foreach ($report['records'] as $click)
                        <li class="flex items-start gap-3 py-3">
                            @if ($click->sponsorship?->event)
                                <img src="{{ \App\Filament\Support\PosterColumn::url($click->sponsorship->event) }}" alt="" width="36" height="48" loading="lazy" decoding="async" class="h-12 w-9 shrink-0 rounded border border-gray-200 bg-gray-100 object-cover dark:border-white/10 dark:bg-white/5">
                            @endif
                            <div class="flex min-w-0 flex-col gap-0.5">
                            <p class="font-medium text-gray-950 [overflow-wrap:anywhere] dark:text-white">{{ $click->sponsorship?->event?->title ?? __('sponsorships.analytics.event_gone') }}</p>
                            <p class="{{ $muted }}">
                                {{ \App\Filament\Support\PanelDate::moment($click->clicked_at, $tz) }}
                                @if ($click->sponsorship?->event?->venue?->name) · {{ $click->sponsorship->event->venue->name }} @endif
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ __('sponsorships.analytics.channels.'.($click->channel === 'android' ? 'android' : 'web')) }}
                                · {{ \App\Enums\SponsorshipPlacement::tryFrom((string) $click->placement)?->label() ?? (\Illuminate\Support\Facades\Lang::has('sponsorships.analytics.placements.'.$click->placement) ? __('sponsorships.analytics.placements.'.$click->placement) : $click->placement) }}
                                · {{ __('sponsorships.analytics.pages.'.(in_array($click->page, ['home', 'feed', 'event', 'venues', 'profile', 'search'], true) ? $click->page : 'other')) }}
                            </p>
                            </div>
                        </li>
                    @endforeach
                </ul>
                <x-filament::pagination :paginator="$report['records']" class="mt-4" />
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
