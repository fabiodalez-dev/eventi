{{--
    Chi occupa già la collocazione nel periodo scelto (`SponsorshipCapacity::occupants`).

    Variabili: `ready` (bool: collocazione, città e date ci sono), `placement`
    (enum o null), `timezone`, `occupants` (elenco di unità).
--}}
@php
    use App\Enums\SponsorshipPlacement;
    use App\Filament\Support\PanelDate;

    $occupants ??= [];
    $isHero = ($placement ?? null) === SponsorshipPlacement::HomeHero;
@endphp
<section class="flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5" data-occupants aria-labelledby="occupants-heading">
    <h3 id="occupants-heading" class="text-base font-semibold text-gray-950 dark:text-white">{{ __('sponsorships.wizard.occupants.heading') }}</h3>

    @if (! ($ready ?? false))
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('sponsorships.wizard.occupants.waiting') }}</p>
    @elseif ($occupants === [])
        <p class="text-sm text-gray-600 dark:text-gray-300" data-occupants-empty>{{ __('sponsorships.wizard.occupants.empty') }}</p>
    @else
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ trans_choice('sponsorships.wizard.occupants.lead', count($occupants), ['count' => count($occupants)]) }}</p>
        @if ($isHero)
            <p class="rounded-lg bg-warning-50 px-3 py-2 text-sm text-warning-800 dark:bg-warning-400/10 dark:text-warning-300" data-occupants-hero-warning>{{ __('sponsorships.wizard.occupants.hero_warning') }}</p>
        @endif
        <ul class="flex flex-col divide-y divide-gray-100 dark:divide-white/10" role="list">
            @foreach ($occupants as $unit)
                <li class="flex flex-col gap-0.5 py-2 first:pt-0 last:pb-0" data-occupant="{{ $unit['key'] }}">
                    <span class="font-semibold text-gray-950 dark:text-white [overflow-wrap:anywhere]">{{ $unit['name'] }}</span>
                    <span class="text-sm text-gray-600 dark:text-gray-300">
                        {{ PanelDate::period($unit['starts_at'], $unit['ends_at'], $timezone) }}
                        · {{ $unit['kind'] === 'grant' ? trans_choice('sponsorships.wizard.occupants.events', $unit['events'], ['count' => $unit['events']]) : __('sponsorships.wizard.occupants.single') }}
                        · {{ $unit['goal'] === null ? __('sponsorships.wizard.occupants.period') : __('sponsorships.wizard.occupants.goal', ['goal' => number_format($unit['goal'], 0, ',', '.')]) }}
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</section>
