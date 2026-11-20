{{--
    Il riepilogo in chiaro prima di salvare (ultimo passo del percorso).
    Variabili: vedi `SponsorshipWizard::summaryData()`.
--}}
@php
    use App\Filament\Support\PanelDate;

    $money = static fn (?float $euro): string => $euro === null ? __('sponsorships.wizard.summary.no_amount') : \Illuminate\Support\Number::currency($euro, in: 'EUR', locale: 'it');
    $rows = [];
    $rows[__('sponsorships.wizard.summary.advertiser')] = filled($advertiser) ? $advertiser : __('sponsorships.wizard.summary.missing');
    $rows[__('sponsorships.wizard.summary.what')] = $scope === 'all'
        ? trans_choice('sponsorships.wizard.summary.all_events', (int) $eventsCount, ['count' => (int) $eventsCount, 'sponsor' => $sponsor ?? '—'])
        : ($event?->title ?? __('sponsorships.wizard.summary.missing'));
    $rows[__('sponsorships.wizard.summary.where')] = $placement ?? __('sponsorships.wizard.summary.missing');
    $rows[__('sponsorships.wizard.summary.when')] = $starts !== null ? PanelDate::period($starts, $ends, $timezone) : __('sponsorships.wizard.summary.missing');
    $rows[__('sponsorships.wizard.summary.product')] = $views
        ? __('sponsorships.wizard.summary.views', ['goal' => number_format((int) $goal, 0, ',', '.'), 'pacing' => mb_strtolower((string) $pacing)])
        : __('sponsorships.wizard.summary.period');
    $rows[__('sponsorships.wizard.summary.payment')] = $complimentary ? __('sponsorships.wizard.summary.complimentary') : $money($amount);
    $rows[__('sponsorships.wizard.summary.status')] = $active ? __('sponsorships.wizard.statuses.active') : __('sponsorships.wizard.statuses.draft');
    $rows[__('sponsorships.wizard.summary.advanced')] = __('sponsorships.wizard.summary.advanced_value', ['priority' => $priority, 'weight' => $weight]);
@endphp
<section class="flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5" data-wizard-summary aria-labelledby="wizard-summary-heading">
    <h3 id="wizard-summary-heading" class="text-base font-semibold text-gray-950 dark:text-white">{{ __('sponsorships.wizard.summary.heading') }}</h3>
    <p class="text-sm text-gray-600 dark:text-gray-300">{{ $scope === 'all' ? __('sponsorships.wizard.summary.lead_grant') : __('sponsorships.wizard.summary.lead_single') }}</p>

    @if ($event !== null)
        <x-pannelli::event-row :event="$event" :venue="$event->venue?->name" size="sm" />
    @endif

    <dl class="flex flex-col divide-y divide-gray-100 dark:divide-white/10">
        @foreach ($rows as $label => $value)
            <div class="flex flex-col gap-0.5 py-2 sm:flex-row sm:gap-4">
                <dt class="text-sm font-semibold text-gray-950 sm:w-44 sm:shrink-0 dark:text-white">{{ $label }}</dt>
                <dd class="text-sm text-gray-700 [overflow-wrap:anywhere] dark:text-gray-300">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    @if ($scope === 'all' && ! $complimentary && ! $paid)
        <p class="rounded-lg bg-warning-50 px-3 py-2 text-sm text-warning-800 dark:bg-warning-400/10 dark:text-warning-300" data-summary-unpaid>{{ __('sponsorships.wizard.summary.unpaid_warning') }}</p>
    @endif
    @if ($scope === 'all' && filled($advertiser) && filled($sponsor) && $advertiser !== $sponsor)
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('sponsorships.wizard.summary.grant_name', ['name' => $sponsor]) }}</p>
    @endif
</section>
