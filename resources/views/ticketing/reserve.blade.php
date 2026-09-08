<x-layouts.app :narrow="true" :meta="$meta">
    <div class="mx-auto flex max-w-2xl flex-col gap-6">
        <header><p class="text-brand font-bold">{{ __('ticketing.free') }}</p><h1 class="text-hero">{{ $date->event->title }}</h1>
            <p class="mt-3">{{ $date->starts_at->timezone($date->event->city->timezone)->format('d/m/Y H:i') }} · {{ $date->effectiveVenue()?->name }}</p></header>
        @include('ticketing.errors')
        <p class="text-sm text-ink-muted">{{ __('ticketing.admission_notice') }}</p>
        @if ($availability['open'])
            <p>{{ $availability['remaining'] === null ? __('ticketing.unlimited') : __('ticketing.remaining', ['count' => $availability['remaining']]) }}</p>
            <p class="text-sm text-ink-muted">{{ __('ticketing.limit', ['count' => $availability['limit_per_account']]) }}</p>
            @if ($date->booking_instructions)<p class="whitespace-pre-line">{{ $date->booking_instructions }}</p>@endif
            <p class="text-sm">{{ __('ticketing.cancellation_until', ['date' => ($date->cancellation_closes_at ?? $date->starts_at)->timezone($date->event->city->timezone)->format('d/m/Y H:i')]) }}</p>
            <form method="POST" action="{{ route('tickets.store', $date) }}" class="flex flex-col gap-5" data-reservation-form data-limit="{{ $availability['limit_per_account'] }}">
                @csrf
                <input type="hidden" name="request_key" value="{{ old('request_key', (string) \Illuminate\Support\Str::uuid()) }}">
                <fieldset class="grid gap-4"><legend class="mb-4 text-xl font-bold">{{ __('ticketing.booker') }}</legend>
                    <p class="text-sm text-ink-muted">{{ auth()->user()->email }}</p>
                    @foreach (['first_name', 'last_name'] as $field)
                        <label class="text-sm font-bold">{{ __('ticketing.fields.'.$field) }}<input name="booker[{{ $field }}]" value="{{ old('booker.'.$field) }}" required maxlength="120" autocomplete="{{ $field === 'first_name' ? 'given-name' : 'family-name' }}" class="mt-2 w-full border border-line bg-surface p-3 text-ink"></label>
                    @endforeach
                    @foreach ($availability['booker_fields'] as $field)
                        <label class="text-sm font-bold">{{ $field['label'] }} · {{ __('ticketing.field_modes.'.($field['required'] ? 'required' : 'optional')) }}<input name="booker[{{ $field['key'] }}]" value="{{ old('booker.'.$field['key']) }}" @required($field['required']) maxlength="255" class="mt-2 w-full border border-line bg-surface p-3 text-ink"></label>
                    @endforeach
                </fieldset>
                <h2 class="text-xl font-bold">{{ __('ticketing.participants') }}</h2>
                <div data-attendees class="flex flex-col gap-4">
                @foreach (old('attendees', [['first_name' => '', 'last_name' => '']]) as $index => $name)
                    <div data-attendee class="flex flex-wrap items-end gap-2">
                        @foreach (['first_name', 'last_name'] as $field)
                        <label class="min-w-32 flex-1 text-sm font-bold">{{ __('ticketing.fields.'.$field) }}
                            <input name="attendees[{{ $index }}][{{ $field }}]" data-name-part="{{ $field }}" value="{{ is_array($name) ? ($name[$field] ?? '') : '' }}" required maxlength="120" class="mt-2 w-full border border-line bg-surface p-3 text-ink"></label>
                        @endforeach
                        <button type="button" data-remove-attendee hidden class="min-h-12 px-3 text-brand">{{ __('ticketing.remove') }}</button>
                    </div>
                @endforeach
                </div>
                <x-button variant="secondary" data-add-attendee hidden>{{ __('ticketing.add_attendee') }}</x-button>
                @if ($availability['waitlist'])<label class="flex gap-3 text-sm"><input type="checkbox" name="waitlist" value="1" @checked(old('waitlist')) class="size-5 shrink-0">{{ __('ticketing.waitlist_consent') }}</label>@endif
                <label class="flex gap-3 text-sm"><input type="checkbox" name="accept_terms" value="1" required class="size-5 shrink-0">{{ __('ticketing.privacy_label') }}</label>
                <a href="{{ $availability['privacy_url'] }}" target="_blank" rel="noopener" class="text-brand underline">{{ __('ticketing.privacy_link') }}</a>
                <x-button type="submit" class="min-h-12">{{ __('ticketing.confirm') }}</x-button>
            </form>
        @else
            <p>{{ __('ticketing.errors.closed') }}</p>
        @endif
        <x-button variant="secondary" :href="route('tickets.index')">{{ __('ticketing.title') }}</x-button>
    </div>
</x-layouts.app>
