<x-layouts.app :meta="$meta"><div class="flex flex-col gap-6">
    <a href="{{ route('ticketing.manage.index') }}" class="text-brand">{{ __('ticketing.manage') }}</a>
    <h1 class="text-hero">{{ $date->event->title }}</h1>
    <p>{{ $date->starts_at->timezone($date->event->city->timezone)->format('d/m/Y H:i') }} · {{ $date->effectiveVenue()?->name }}</p>
    @include('ticketing.errors')
    <dl class="flex flex-wrap gap-x-8 gap-y-3 text-sm">
        @foreach (['valid', 'checked_in', 'waitlisted', 'cancelled'] as $status)
            <div><dt class="text-ink-muted">{{ __('ticketing.statuses.'.$status) }}</dt><dd class="font-bold">{{ $statistics[$status] ?? 0 }}</dd></div>
        @endforeach
    </dl>
    <p>{{ $availability['remaining'] === null ? __('ticketing.unlimited') : __('ticketing.remaining', ['count' => $availability['remaining']]) }}</p>
    @if (! $date->effectiveVenue()?->ticketing_enabled)<p>{{ __('ticketing.disabled_hint') }}</p>@else
    <details class="border border-line p-4"><summary class="cursor-pointer font-bold py-2">{{ __('ticketing.settings') }}</summary>
        <form action="{{ route('ticketing.manage.configure', $date) }}" method="POST" class="mt-5 grid gap-5 md:grid-cols-2">@csrf
            <label><input type="hidden" name="booking_enabled" value="0"><input type="checkbox" name="booking_enabled" value="1" @checked(old('booking_enabled', $date->booking_enabled))> {{ __('ticketing.enabled') }}</label>
            <label><input type="hidden" name="booking_waitlist" value="0"><input type="checkbox" name="booking_waitlist" value="1" @checked(old('booking_waitlist', $date->booking_waitlist))> {{ __('ticketing.waitlist') }}</label>
            <x-field name="booking_capacity" type="number" :label="__('ticketing.capacity')" :hint="__('ticketing.capacity_hint')" :value="$date->booking_capacity" />
            <x-field name="booking_limit" type="number" :label="__('ticketing.booking_limit')" :value="$date->booking_limit" :required="true" />
            <p class="text-sm text-ink-muted md:col-span-2">{{ __('ticketing.time_hint', ['timezone' => $date->event->city->timezone]) }}</p>
            @foreach (['booking_opens_at' => 'opens_at', 'booking_closes_at' => 'closes_at', 'cancellation_closes_at' => 'cancellation_closes_at'] as $field => $label)
                <x-field :name="$field" type="datetime-local" :label="__('ticketing.'.$label)" :value="$date->$field?->timezone($date->event->city->timezone)->format('Y-m-d\TH:i')" />
            @endforeach
            <x-field name="booking_instructions" type="textarea" :label="__('ticketing.instructions')" :value="$date->booking_instructions" class="md:col-span-2" />
            <fieldset class="grid gap-4 md:col-span-2"><legend class="mb-3 font-bold">{{ __('ticketing.form_fields') }}</legend>
                <p class="text-sm text-ink-muted">{{ __('ticketing.form_hint') }}</p>
                @foreach (\App\Services\Ticketing\BookingForm::FIELDS as $field)
                    <label class="flex flex-wrap items-center justify-between gap-3 text-sm">{{ __('ticketing.fields.'.$field) }}
                        <select name="booking_fields[{{ $field }}]" class="min-h-12 border border-line bg-surface p-3 text-ink">
                            @foreach (\App\Enums\BookingFieldMode::cases() as $mode)<option value="{{ $mode->value }}" @selected(old('booking_fields.'.$field, $date->booking_fields[$field] ?? 'hidden') === $mode->value)>{{ __('ticketing.field_modes.'.$mode->value) }}</option>@endforeach
                        </select>
                    </label>
                @endforeach
            </fieldset>
            <x-button type="submit" class="min-h-12">{{ __('ticketing.save') }}</x-button>
        </form>
    </details>@endif
    <section class="border-t-2 border-line pt-5"><h2 class="text-2xl font-bold">{{ __('ticketing.checkin') }}</h2>
        <form method="POST" action="{{ route('ticketing.manage.checkin', $date) }}" class="mt-4 flex flex-col gap-4" data-ticket-scanner data-fallback="{{ __('ticketing.scanner_fallback') }}" data-ready="{{ __('ticketing.scanner_ready') }}">@csrf
            <div class="flex gap-3"><x-button variant="secondary" data-scan-start>{{ __('ticketing.scan') }}</x-button><x-button variant="secondary" data-scan-stop hidden>{{ __('ticketing.stop_scan') }}</x-button></div>
            <video hidden muted playsinline class="w-full max-w-md" aria-label="{{ __('ticketing.scan') }}"></video><p data-scan-message role="status"></p>
            <x-field name="code" :label="__('ticketing.code')" :required="true" autocomplete="off" />
            <x-button type="submit" class="min-h-12">{{ __('ticketing.checkin') }}</x-button>
        </form>
    </section>
    <div class="flex flex-wrap gap-4 items-end"><h2 class="text-2xl font-bold">{{ __('ticketing.participants') }}</h2><x-button variant="secondary" :href="route('ticketing.manage.export', $date)">{{ __('ticketing.export') }}</x-button></div>
    <form method="GET" class="flex gap-3 items-end"><x-field name="q" :label="__('ticketing.search')" :value="request('q')" /><x-button type="submit">{{ __('ticketing.filter') }}</x-button></form>
    @foreach ($bookings as $booking)
        <section class="border-t border-line pt-4"><p class="text-sm">{{ __('ticketing.booking_number', ['id' => $booking->id]) }} · {{ $booking->user?->email }}</p>
            @if ($booking->booker_data)<details><summary class="cursor-pointer py-3 text-brand">{{ __('ticketing.booker') }}</summary><dl class="grid gap-3 text-sm">
                @foreach ($booking->booker_data as $field => $value)@if ($value)<div><dt class="text-ink-muted">{{ __('ticketing.fields.'.$field) }}</dt><dd class="whitespace-pre-line">{{ $value }}</dd></div>@endif @endforeach
                <div><dt>{{ __('ticketing.privacy_record') }}</dt><dd>{{ $booking->privacy_accepted_at?->timezone($date->event->city->timezone)->format('d/m/Y H:i') }}</dd></div>
            </dl></details>@endif
            @foreach ($booking->tickets as $ticket)
                <div class="flex flex-wrap items-center justify-between gap-3 py-3"><div><strong>{{ $ticket->attendee_name }}</strong><p class="text-sm">#{{ $ticket->id }} · {{ __('ticketing.statuses.'.$ticket->status->value) }}</p></div>
                    @if ($ticket->status === \App\Enums\AdmissionStatus::Valid)<form method="POST" action="{{ route('ticketing.manage.checkin', $date) }}">@csrf<input type="hidden" name="code" value="{{ $ticket->code }}"><x-button type="submit">{{ __('ticketing.checkin') }}</x-button></form>@endif
                    @if ($ticket->status !== \App\Enums\AdmissionStatus::Cancelled)<form method="POST" action="{{ route('ticketing.manage.cancel', $booking) }}" data-confirm="{{ __('ticketing.confirm_cancel') }}">@csrf<input type="hidden" name="ticket_id" value="{{ $ticket->id }}"><x-button type="submit" variant="ghost">{{ __('ticketing.cancel_ticket') }}</x-button></form>@endif
                </div>
            @endforeach
            @if ($booking->status !== \App\Enums\BookingStatus::Cancelled)
                <details><summary class="cursor-pointer py-3 text-brand">{{ __('ticketing.cancel') }}</summary><form method="POST" action="{{ route('ticketing.manage.cancel', $booking) }}" class="flex flex-col gap-3" data-confirm="{{ __('ticketing.confirm_cancel') }}">@csrf<x-field name="reason" :label="__('ticketing.reason')" /><x-button type="submit" variant="secondary">{{ __('ticketing.cancel') }}</x-button></form></details>
            @endif
        </section>
    @endforeach
    {{ $bookings->links() }}
</div></x-layouts.app>
