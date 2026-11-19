{{--
    Una richiesta di posti. Per il passeggero: il conducente in evidenza, a
    che punto è la richiesta (inviata → confermata → viaggio), «Scrivi a…»
    quando la chat è aperta, «Rinuncia» con conferma. Per il conducente:
    chi chiede e Accetta/Rifiuta, che riportano all'offerta.
--}}
@php
    $driverView = $ride['is_driver'];
    $other = $driverView ? $ride['requester']['name'] : $offer['driver']['name'];
    $open = in_array($ride['status'], ['pending', 'accepted'], true);
    $stepIndex = ['pending' => 1, 'accepted' => 2][$ride['status']] ?? null;
    $statusTone = $ride['status'] === 'accepted' ? 'brand' : ($ride['status'] === 'pending' ? 'soon' : 'muted');
@endphp
<x-layouts.app service :meta="$meta">
    <x-service-page :title="$driverView ? __('carpool.web.request_driver_title', ['name' => $other]) : __('carpool.web.request_own_title')"
        :subtitle="$offer['departure_label'] ? __('carpool.web.zone_at', ['zone' => $offer['zone'], 'time' => $offer['departure_label']]) : $ride['status_label']" :eyebrow="$offer['leg_label'].' · '.__('carpool.free')"
        :back="$driverView ? route('carpool.offer', $offer['id']) : route('carpool.index')" :back-label="$driverView ? __('carpool.web.own_title') : __('carpool.web.back_mine')"
        :tabs="$tabs" :tabs-label="__('carpool.web.tabs.label')">
        <x-slot:actions>
            @if ($ride['chat_id'] && $ride['status'] === 'accepted')
                <x-button :href="route('carpool.chat', $ride['chat_id'])"><x-lucide name="message-circle" class="size-4" />{{ __('carpool.web.write_to', ['name' => $other]) }}</x-button>
            @elseif ($ride['chat_id'])
                <x-button variant="secondary" :href="route('carpool.chat', $ride['chat_id'])">{{ __('carpool.open_chat') }}</x-button>
            @endif
            <x-button variant="secondary" :href="$offer['url']">{{ __('carpool.web.chat_ride') }}</x-button>
        </x-slot:actions>

        @include('carpool.partials.event')

        <x-card :title="$driverView ? __('carpool.web.passenger_title') : __('carpool.web.driver_title')" data-request-person>
            <x-slot:actions><x-badge :tone="$statusTone">{{ $ride['status_label'] }}</x-badge></x-slot:actions>
            <div class="flex min-w-0 items-center gap-4">
                <x-avatar :name="$other" size="lg" />
                <div class="flex min-w-0 flex-col gap-1">
                    <p class="font-display text-xl leading-tight font-extrabold text-ink">{{ $other }}</p>
                    @if (! $driverView && $offer['driver']['verified'])<p class="flex items-center gap-1.5 text-sm text-ink-muted"><x-lucide name="check" class="size-4 text-accent" />{{ __('carpool.verified') }}</p>@endif
                    <p class="text-sm text-ink-muted">{{ trans_choice('carpool.web.seats_requested', $ride['seats'], ['count' => $ride['seats']]) }}</p>
                    @if (! $driverView && ! empty($offer['reviews']))
                        <p class="text-sm"><a class="text-brand underline" href="{{ $offer['reviews_url'] }}">{{ __('carpool.reviews.open') }}</a></p>
                    @endif
                </div>
            </div>
            @if ($ride['note'])<p class="text-sm whitespace-pre-line break-words text-ink">{{ $ride['note'] }}</p>@endif
            @if ($ride['can_decide'])
                <x-slot:footer>
                    @foreach (['accept', 'decline'] as $decision)
                        <form method="post" action="{{ route('carpool.action', $decision) }}">
                            @include('carpool.partials.key')
                            <input type="hidden" name="request_id" value="{{ $ride['id'] }}">
                            <x-button type="submit" :variant="$decision === 'accept' ? 'primary' : 'secondary'"><x-lucide :name="$decision === 'accept' ? 'check' : 'x'" class="size-4" />{{ __('carpool.'.$decision) }}</x-button>
                        </form>
                    @endforeach
                </x-slot:footer>
            @endif
        </x-card>

        <x-card :title="__('carpool.web.progress')" data-request-progress>
            @if ($stepIndex !== null)
                <ol class="m-0 flex list-none flex-col gap-3 p-0">
                    @foreach (['sent', 'accepted', 'trip'] as $index => $step)
                        @php($state = $index < $stepIndex ? 'done' : ($index === $stepIndex ? 'current' : 'next'))
                        <li class="flex items-center gap-3 text-sm" data-step="{{ $step }}" data-step-state="{{ $state }}" @if ($state === 'current') aria-current="step" @endif>
                            <span aria-hidden="true" @class(['flex size-8 shrink-0 items-center justify-center font-display text-sm font-extrabold', 'bg-brand text-on-brand' => $state === 'done', 'border-2 border-brand text-brand' => $state === 'current', 'border-2 border-line text-ink-subtle' => $state === 'next'])>
                                @if ($state === 'done')<x-lucide name="check" class="size-4" />@else{{ $index + 1 }}@endif
                            </span>
                            <span @class(['font-semibold text-ink' => $state !== 'next', 'text-ink-muted' => $state === 'next'])>{{ __('carpool.web.progress_steps.'.$step) }}</span>
                            <span class="sr-only">({{ __('carpool.web.progress_state.'.$state) }})</span>
                        </li>
                    @endforeach
                </ol>
                <p class="text-sm text-ink-muted">{{ __('carpool.web.progress_hint.'.$ride['status']) }}</p>
            @else
                <x-notice>{{ __('carpool.web.progress_closed', ['status' => mb_strtolower($ride['status_label'])]) }}</x-notice>
            @endif
        </x-card>

        @if (! $driverView && $ride['status'] === 'accepted' && $ride['can_withdraw'] && $ride['seats'] > 1)
            <x-card :title="__('carpool.web.reduce_title')" :description="__('carpool.web.reduce_description')" data-request-reduce>
                <form method="post" action="{{ route('carpool.action', 'reduce') }}" class="flex flex-col gap-4">
                    @include('carpool.partials.key')
                    <input type="hidden" name="request_id" value="{{ $ride['id'] }}">
                    @include('carpool.partials.field', ['name' => 'seats', 'id' => 'riduci-posti', 'label' => __('carpool.seats'), 'type' => 'number', 'required' => true, 'min' => 1, 'max' => $ride['seats'] - 1, 'value' => old('seats', $ride['seats'] - 1)])
                    <div><x-button type="submit" variant="secondary">{{ __('carpool.reduce') }}</x-button></div>
                </form>
            </x-card>
        @endif

        @if (! $driverView && $ride['can_withdraw'])
            <x-card tone="alert" :title="__('carpool.web.withdraw_title')" :description="__('carpool.web.withdraw_description')" data-request-withdraw>
                <form method="post" action="{{ route('carpool.action', 'withdraw') }}" data-confirm="{{ __('carpool.confirm_withdraw') }}">
                    @include('carpool.partials.key')
                    <input type="hidden" name="request_id" value="{{ $ride['id'] }}">
                    <x-button type="submit" variant="danger">{{ __('carpool.withdraw') }}</x-button>
                </form>
            </x-card>
        @endif

        @include('carpool.partials.review-form')

        @if ($ride['can_feedback'])
            <x-card padding="sm" data-request-feedback>
                <details class="group">
                    <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-3">
                        <span class="flex flex-col"><span class="font-semibold text-ink">{{ __('carpool.feedback') }}</span><span class="text-sm text-ink-muted">{{ __('carpool.feedback_help') }}</span></span>
                        <x-lucide name="chevron-down" class="size-5 shrink-0 text-ink-muted transition group-open:rotate-180" />
                    </summary>
                    <form method="post" action="{{ route('carpool.discovery', 'feedback') }}" class="mt-4 flex flex-col gap-4">
                        @include('carpool.partials.key')
                        <input type="hidden" name="request_id" value="{{ $ride['id'] }}">
                        @include('carpool.partials.field', ['name' => 'kind', 'id' => 'feedback-tipo', 'label' => __('carpool.feedback'), 'type' => 'select',
                            'options' => collect(\App\Enums\RideFeedbackKind::cases())->mapWithKeys(fn ($kind) => [$kind->value => $kind->label()])->all(), 'value' => old('kind')])
                        @include('carpool.partials.field', ['name' => 'body', 'id' => 'feedback-testo', 'label' => __('carpool.details'), 'type' => 'textarea', 'maxlength' => 1000, 'value' => old('body')])
                        <div><x-button type="submit">{{ __('carpool.save') }}</x-button></div>
                    </form>
                </details>
            </x-card>
        @endif

        @include('carpool.partials.report')
    </x-service-page>
</x-layouts.app>
