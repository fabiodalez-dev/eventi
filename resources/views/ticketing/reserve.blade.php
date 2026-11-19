{{--
    Prenotazione gratuita di una data (`/biglietti/prenota/{data}`). In cima
    il riepilogo dell'evento con la locandina e la disponibilità; sotto il
    modulo, una card per argomento: chi prenota (già compilato con i dati
    della persona), domande di chi organizza, partecipanti (il primo è chi
    prenota, con «Partecipo anch'io»), conferma. Il contratto del POST è
    quello di sempre: `booker[…]`, `answers[…]`, `attendees[n][first_name|last_name]`.
--}}
@php
    $timezone = $date->event->city->timezone ?? config('app.timezone');
    $state = $availability['sale_state'];
    $remaining = $availability['remaining'];
    $limit = (int) $availability['limit_per_account'];
    $venue = $date->effectiveVenue();
    $address = $date->locationVenue()?->address;
    $fullError = collect($errors->get('ticketing'))->contains(__('ticketing.errors.full'));
    /* La casella della lista d'attesa serve solo quando i posti potrebbero
       non bastare: esauriti, meno posti del massimo per account, o un invio
       appena respinto perché i posti non bastavano. */
    $showWaitlist = $availability['waitlist'] && ($state === \App\Enums\TicketTierStatus::SoldOut->value
        || ($remaining !== null && $remaining < $limit) || old('waitlist') || $fullError);
    $self = old('attendees') === null || (bool) old('self_attending');
    $attendees = old('attendees', [['first_name' => $prefill['first_name'] ?? '', 'last_name' => $prefill['last_name'] ?? '']]);
    $attendees = is_array($attendees) && $attendees !== [] ? array_values($attendees) : [['first_name' => '', 'last_name' => '']];
    $control = 'min-h-12 w-full border border-line bg-surface px-3.5 py-2.5 text-sm text-ink placeholder:text-ink-subtle focus:border-brand focus:outline-none read-only:bg-surface-sunken read-only:text-ink-muted';
    $stateTone = match ($state) {
        \App\Enums\TicketTierStatus::Available->value => 'brand',
        \App\Enums\TicketTierStatus::NotYetOnSale->value => 'soon',
        default => 'muted',
    };
@endphp
<x-layouts.app service :meta="$meta">
    <x-service-page
        :title="__('ticketing.reserve')"
        :subtitle="__('ticketing.free')"
        :back="\App\Support\EventUrl::occurrence($date)"
        :back-label="__('ticketing.reserve_page.back')"
    >
        <x-card :title="__('ticketing.reserve_page.summary')" data-reserve-summary>
            <x-service-event-row :occurrence="$date">
                <x-slot:badge><x-price-tag :event="$date->event" :occurrence="$date" size="sm" /></x-slot:badge>
                @if (filled($address))
                    <span>{{ $address }}</span>
                @endif
            </x-service-event-row>
            <p class="border-t border-line pt-4 text-sm text-ink-muted">{{ __('ticketing.admission_notice') }}</p>
        </x-card>

        <x-card :title="__('ticketing.reserve_page.availability')" data-reserve-availability="{{ $state }}">
            <x-slot:actions>
                <x-badge :tone="$stateTone" size="sm">{{ __('ticketing.reserve_page.states.'.$state) }}</x-badge>
            </x-slot:actions>
            @if ($availability['open'])
                <p class="font-display text-lg font-extrabold text-ink">{{ $remaining === null ? __('ticketing.unlimited') : __('ticketing.remaining', ['count' => $remaining]) }}</p>
                <ul class="flex list-disc flex-col gap-1 pl-5 text-sm text-ink-muted">
                    <li>{{ __('ticketing.limit', ['count' => $limit]) }}</li>
                    <li>{{ __('ticketing.cancellation_until', ['date' => ($date->cancellation_closes_at ?? $date->starts_at)->timezone($timezone)->format('d/m/Y H:i')]) }}</li>
                </ul>
                @if ($state === \App\Enums\TicketTierStatus::SoldOut->value)
                    <x-notice>{{ $availability['waitlist'] ? __('ticketing.reserve_page.sold_out_waitlist') : __('ticketing.reserve_page.sold_out_closed') }}</x-notice>
                @endif
                @if ($date->booking_instructions)
                    <div class="flex flex-col gap-2 border-t border-line pt-4 text-sm text-ink">
                        <h3 class="font-semibold">{{ __('ticketing.instructions') }}</h3>
                        <x-description-content :text="$date->booking_instructions" />
                    </div>
                @endif
            @else
                <p class="text-sm text-ink-muted">{{ match ($state) {
                    \App\Enums\TicketTierStatus::NotYetOnSale->value => __('ticketing.reserve_page.opens_on', ['date' => \Illuminate\Support\Carbon::parse($availability['opens_at'])->timezone($timezone)->format('d/m/Y H:i')]),
                    \App\Enums\TicketTierStatus::SoldOut->value => __('ticketing.reserve_page.sold_out_closed'),
                    default => __('ticketing.reserve_page.closed_reason'),
                } }}</p>
                <div><x-button variant="secondary" :href="\App\Support\EventUrl::occurrence($date)">{{ __('ticketing.reserve_page.event_link') }}</x-button></div>
            @endif
        </x-card>

        @if ($availability['open'])
            <form method="POST" action="{{ route('tickets.store', $date) }}" class="flex flex-col gap-6" data-reservation-form data-limit="{{ $limit }}">
                @csrf
                <input type="hidden" name="request_key" value="{{ old('request_key', (string) \Illuminate\Support\Str::uuid()) }}">

                <x-card :title="__('ticketing.booker')" :description="__('ticketing.reserve_page.booker_hint')">
                    <div class="flex flex-col gap-1.5">
                        <label for="prenotante-email" class="text-sm font-semibold text-ink">{{ __('ticketing.reserve_page.email') }}</label>
                        <p id="prenotante-email-aiuto" class="text-xs text-ink-subtle">{{ __('ticketing.reserve_page.email_hint') }}</p>
                        <input id="prenotante-email" type="email" value="{{ auth()->user()->email }}" readonly aria-describedby="prenotante-email-aiuto" class="{{ $control }}">
                    </div>
                    @foreach (['first_name' => 'given-name', 'last_name' => 'family-name'] as $field => $autocomplete)
                        <div class="flex flex-col gap-1.5">
                            <label for="prenotante-{{ $field }}" class="text-sm font-semibold text-ink">{{ __('ticketing.fields.'.$field) }} <span class="text-live" aria-hidden="true">*</span></label>
                            <input id="prenotante-{{ $field }}" name="booker[{{ $field }}]" value="{{ old('booker.'.$field, $prefill[$field] ?? '') }}" required maxlength="120" autocomplete="{{ $autocomplete }}" data-booker-part="{{ $field }}" @if ($errors->has('booker.'.$field) || $errors->has($field)) aria-invalid="true" @endif class="{{ $control }}">
                            @if ($message = $errors->first('booker.'.$field) ?: $errors->first($field))<p class="text-xs font-semibold text-live">{{ $message }}</p>@endif
                        </div>
                    @endforeach
                    @foreach ($availability['booker_fields'] as $field)
                        <div class="flex flex-col gap-1.5">
                            <label for="prenotante-{{ $field['key'] }}" class="text-sm font-semibold text-ink">{{ $field['label'] }} <span class="font-normal text-ink-muted">· {{ __('ticketing.field_modes.'.($field['required'] ? 'required' : 'optional')) }}</span></label>
                            <input id="prenotante-{{ $field['key'] }}" name="booker[{{ $field['key'] }}]" value="{{ old('booker.'.$field['key'], $prefill[$field['key']] ?? '') }}" @required($field['required']) maxlength="255" @if ($field['key'] === 'phone') type="tel" autocomplete="tel" @endif @if ($errors->has($field['key'])) aria-invalid="true" @endif class="{{ $control }}">
                            @error($field['key'])<p class="text-xs font-semibold text-live">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                </x-card>

                @if ($availability['questions'] !== [])
                    <x-card :title="__('ticketing.questions.title')" :description="__('ticketing.questions.public_hint')" data-booking-questions>
                        @foreach ($availability['questions'] as $question)
                            @php $label = $question['label'].' · '.__('ticketing.field_modes.'.($question['required'] ? 'required' : 'optional')); @endphp
                            @if ($question['type'] === 'choice')
                                <label class="flex flex-col gap-1.5 text-sm font-semibold text-ink">{{ $label }}<select name="answers[{{ $question['id'] }}]" @required($question['required']) class="{{ $control }}"><option value="">{{ __('ticketing.questions.choose') }}</option>@foreach ($question['options'] as $option)<option value="{{ $option }}" @selected(old('answers.'.$question['id']) === $option)>{{ $option }}</option>@endforeach</select></label>
                            @elseif ($question['type'] === 'yes_no')
                                <fieldset class="flex flex-col gap-1.5 text-sm text-ink"><legend class="font-semibold">{{ $label }}</legend><div class="flex gap-6">
                                    <label class="flex min-h-12 items-center gap-2"><input type="radio" name="answers[{{ $question['id'] }}]" value="1" @required($question['required']) @checked(old('answers.'.$question['id']) === '1') class="size-5 accent-brand">{{ __('ticketing.questions.yes') }}</label>
                                    <label class="flex min-h-12 items-center gap-2"><input type="radio" name="answers[{{ $question['id'] }}]" value="0" @checked(old('answers.'.$question['id']) === '0') class="size-5 accent-brand">{{ __('ticketing.questions.no') }}</label>
                                </div></fieldset>
                            @else
                                <label class="flex flex-col gap-1.5 text-sm font-semibold text-ink">{{ $label }}<textarea name="answers[{{ $question['id'] }}]" @required($question['required']) maxlength="{{ \App\Services\Ticketing\BookingForm::ANSWER_MAX }}" rows="2" class="{{ $control }}">{{ old('answers.'.$question['id']) }}</textarea></label>
                            @endif
                            @error($question['id'])<p class="text-xs font-semibold text-live">{{ $message }}</p>@enderror
                        @endforeach
                    </x-card>
                @endif

                <x-card :title="__('ticketing.participants')" :description="__('ticketing.reserve_page.participants_hint')">
                    <x-slot:actions>
                        <span class="text-sm font-semibold text-ink-muted tabular-nums" data-attendee-counter data-template="{{ __('ticketing.reserve_page.counter') }}" aria-live="polite">{{ __('ticketing.reserve_page.counter', ['count' => count($attendees), 'limit' => $limit]) }}</span>
                    </x-slot:actions>
                    <div data-attendees class="flex flex-col gap-4" data-self-label="{{ __('ticketing.reserve_page.self') }}" data-number-label="{{ __('ticketing.reserve_page.attendee') }}">
                        @foreach ($attendees as $index => $name)
                            @php $isSelf = $index === 0 && $self; @endphp
                            <div data-attendee role="group" aria-label="{{ $isSelf ? __('ticketing.reserve_page.self') : __('ticketing.reserve_page.attendee', ['number' => $index + 1]) }}" class="flex flex-col gap-3 border border-line bg-canvas p-4">
                                <div class="flex min-h-10 flex-wrap items-center justify-between gap-2">
                                    <h3 class="font-display text-base font-extrabold text-ink" data-attendee-title>{{ $isSelf ? __('ticketing.reserve_page.self') : __('ticketing.reserve_page.attendee', ['number' => $index + 1]) }}</h3>
                                    <x-button type="button" variant="secondary" size="sm" data-remove-attendee hidden>{{ __('ticketing.remove') }}</x-button>
                                </div>
                                @if ($index === 0)
                                    <label class="flex min-h-12 items-center gap-3 text-sm font-semibold text-ink"><input type="checkbox" name="self_attending" value="1" data-self-toggle @checked($self) class="size-5 accent-brand shrink-0">{{ __('ticketing.reserve_page.self_toggle') }}</label>
                                @endif
                                @foreach (['first_name', 'last_name'] as $field)
                                    <label class="flex flex-col gap-1.5 text-sm font-semibold text-ink">{{ __('ticketing.fields.'.$field) }}
                                        <input name="attendees[{{ $index }}][{{ $field }}]" data-name-part="{{ $field }}" value="{{ is_array($name) ? ($name[$field] ?? '') : '' }}" required maxlength="120" class="{{ $control }}">
                                    </label>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                    <template data-attendee-template>
                        <div data-attendee role="group" class="flex flex-col gap-3 border border-line bg-canvas p-4">
                            <div class="flex min-h-10 flex-wrap items-center justify-between gap-2">
                                <h3 class="font-display text-base font-extrabold text-ink" data-attendee-title></h3>
                                <x-button type="button" variant="secondary" size="sm" data-remove-attendee>{{ __('ticketing.remove') }}</x-button>
                            </div>
                            @foreach (['first_name', 'last_name'] as $field)
                                <label class="flex flex-col gap-1.5 text-sm font-semibold text-ink">{{ __('ticketing.fields.'.$field) }}
                                    <input name="attendees[0][{{ $field }}]" data-name-part="{{ $field }}" value="" required maxlength="120" class="{{ $control }}">
                                </label>
                            @endforeach
                        </div>
                    </template>
                    <div><x-button variant="secondary" data-add-attendee hidden><x-lucide name="plus" class="size-4" />{{ __('ticketing.add_attendee') }}</x-button></div>
                </x-card>

                <x-card :title="__('ticketing.reserve_page.confirm_title')">
                    @if ($showWaitlist)
                        <label class="flex items-start gap-3 text-sm text-ink" data-waitlist-option><input type="checkbox" name="waitlist" value="1" @checked(old('waitlist')) class="mt-0.5 size-5 accent-brand shrink-0">{{ __('ticketing.waitlist_consent') }}</label>
                    @endif
                    <label class="flex items-start gap-3 text-sm text-ink"><input type="checkbox" name="accept_terms" value="1" required class="mt-0.5 size-5 accent-brand shrink-0">{{ __('ticketing.privacy_label') }}</label>
                    <a href="{{ $availability['privacy_url'] }}" target="_blank" rel="noopener" class="inline-flex min-h-12 items-center self-start text-sm font-semibold text-brand underline">{{ __('ticketing.privacy_link') }}</a>
                    <x-slot:footer>
                        <x-form-actions>
                            <x-button type="submit">{{ __('ticketing.confirm') }}</x-button>
                        </x-form-actions>
                    </x-slot:footer>
                </x-card>
            </form>
        @endif
    </x-service-page>
</x-layouts.app>
