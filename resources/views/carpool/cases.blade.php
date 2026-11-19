{{-- Le tue segnalazioni (scheda «Aiuto»): elenco, stato e il modulo per una nuova. --}}
<x-layouts.app service :meta="$meta">
    <x-service-page :title="__('carpool.cases')" :subtitle="__('carpool.web.cases_subtitle')" :back="route('account.profile')" :tabs="$tabs" :tabs-label="__('carpool.web.tabs.label')">
        @include('carpool.partials.help-nav')

        @if (empty($cases))
            <x-empty-state :title="__('carpool.web.empty_cases_title')" :description="__('carpool.empty_cases')" />
        @else
            <x-card padding="none" data-carpool-cases>
                @foreach ($cases as $case)
                    <x-card.row :href="route('carpool.case', $case['id'])" icon="info" :title="__('carpool.case', ['id' => $case['id']])"
                        :description="\Illuminate\Support\Facades\Lang::has('carpool.reasons.'.$case['reason']) ? __('carpool.reasons.'.$case['reason']) : null"
                        :badge="__('carpool.CarpoolCaseStatus.'.($case['status'] instanceof \BackedEnum ? $case['status']->value : $case['status']))" />
                @endforeach
            </x-card>
            @include('carpool.partials.pages')
        @endif

        @include('carpool.partials.report')
    </x-service-page>
</x-layouts.app>
