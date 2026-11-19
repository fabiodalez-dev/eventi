{{--
    «Offro passaggio»: il modulo a passi numerati, uno per card. La partenza
    ha `min`/`max` della finestra della tratta scelta (lo script li aggiorna
    quando si cambia tratta); se il servizio rifiuta comunque la partenza, si
    torna qui con i dati scritti e il messaggio sotto il campo.
--}}
<x-layouts.app service :meta="$meta">
    <x-service-page :title="__('carpool.offer')" :subtitle="__('carpool.web.create_subtitle')" :eyebrow="__('carpool.free')"
        :back="route('carpool.dates', $occurrence_id)" :back-label="__('carpool.web.back_dates')" :tabs="$tabs" :tabs-label="__('carpool.web.tabs.label')">
        @include('carpool.partials.event')

        @if (! $access['eligible'])
            <x-card :title="__('carpool.web.blocked_title')">
                @include('carpool.partials.gate', ['hideHeading' => true])
            </x-card>
        @else
            <form method="post" action="{{ route('carpool.action', 'offer') }}" class="flex flex-col gap-6" data-ride-offer-form data-ride-windows='@json($windows)'
                data-window-text="{{ __('carpool.web.departure_window_leg', ['from' => ':from', 'to' => ':to']) }}">
                @include('carpool.partials.key')
                <input type="hidden" name="occurrence_id" value="{{ $occurrence_id }}">

                <x-card :title="__('carpool.web.steps.leg')" :description="__('carpool.web.leg_help')">
                    <fieldset class="flex flex-col gap-3 sm:flex-row">
                        <legend class="sr-only">{{ __('carpool.web.steps.leg') }}</legend>
                        @foreach (\App\Enums\RideLeg::cases() as $leg)
                            <label class="flex min-h-12 flex-1 cursor-pointer items-center gap-3 border-2 border-line bg-surface px-4 py-3 font-semibold text-ink has-[:checked]:border-brand">
                                <input type="radio" name="leg" value="{{ $leg->value }}" @checked(old('leg', 'outbound') === $leg->value) class="size-5 accent-brand">
                                {{ $leg->label() }}
                            </label>
                        @endforeach
                    </fieldset>
                    @if (count($templates))
                        <div class="flex flex-col gap-1.5">
                            <label for="offerta-modello" class="text-sm font-semibold text-ink">{{ __('carpool.templates') }}</label>
                            <p id="offerta-modello-aiuto" class="text-xs text-ink-subtle">{{ __('carpool.web.template_help') }}</p>
                            <select id="offerta-modello" data-ride-template aria-describedby="offerta-modello-aiuto" class="min-h-12 w-full border border-line bg-surface px-3.5 py-2.5 text-sm text-ink focus:border-brand focus:outline-none">
                                <option value="">{{ __('carpool.all') }}</option>
                                @foreach ($templates as $template)
                                    <option value="{{ json_encode($template['settings']) }}">{{ $template['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                </x-card>

                <x-card :title="__('carpool.web.steps.departure')">
                    @include('carpool.partials.offer-fields', ['section' => 'departure'])
                </x-card>

                <x-card :title="__('carpool.web.steps.seats')">
                    @include('carpool.partials.offer-fields', ['section' => 'seats'])
                </x-card>

                <x-card :title="__('carpool.web.steps.details')">
                    @include('carpool.partials.offer-fields', ['section' => 'details'])
                </x-card>

                <x-card :title="__('carpool.web.steps.confirm')" :description="__('carpool.web.draft_help')">
                    <label class="flex items-start gap-3 text-sm text-ink">
                        <input required type="checkbox" name="driver_declaration" value="1" @checked(old('driver_declaration')) class="mt-0.5 size-5 shrink-0 accent-brand">
                        <span>{{ __('carpool.driver_declaration') }}</span>
                    </label>
                    @error('driver_declaration')<p class="text-xs font-semibold text-live">{{ $message }}</p>@enderror
                    <x-form-actions>
                        <x-button type="submit">{{ __('carpool.create') }}</x-button>
                        <x-button type="submit" variant="secondary" name="draft" value="1">{{ __('carpool.draft') }}</x-button>
                    </x-form-actions>
                </x-card>
            </form>
        @endif
    </x-service-page>
</x-layouts.app>
