{{--
    La gestione di una data (`/gestione-biglietti/{data}`). In alto quello che
    serve la sera dell'evento: la testata con la locandina, i numeri, le azioni
    rapide e le prenotazioni con ricerca e filtro per stato. Sotto, chiuse,
    le sezioni che si usano prima: registrare un ingresso a mano, le
    impostazioni, i messaggi, la prenotazione manuale, il personale. A data
    conclusa i moduli spariscono e lo dice un avviso.
--}}
@php
    $timezone = $date->event->city->timezone ?? config('app.timezone');
    $venue = $date->effectiveVenue();
    $ticketingOn = (bool) $venue?->ticketing_enabled;
    $presenze = ($counts['checked_in'] ?? 0) + ($counts['no_show'] ?? 0);
    $filterUrl = fn (?string $value): string => route('ticketing.manage.show', array_filter(['occurrence' => $date->id, 'status' => $value, 'q' => request('q')]));
    $summary = 'flex min-h-16 cursor-pointer list-none items-start justify-between gap-4 px-5 py-4 sm:px-6 [&::-webkit-details-marker]:hidden';
    $ticketTone = fn (\App\Enums\AdmissionStatus $status): string => match ($status) {
        \App\Enums\AdmissionStatus::Valid => 'brand',
        \App\Enums\AdmissionStatus::CheckedIn => 'accent',
        \App\Enums\AdmissionStatus::Waitlisted => 'soon',
        default => 'muted',
    };
    $bookingTone = fn (\App\Models\Booking $booking): string => match ($booking->status) {
        \App\Enums\BookingStatus::Confirmed => 'brand',
        \App\Enums\BookingStatus::Waitlisted => 'soon',
        default => 'muted',
    };
@endphp
<x-layouts.app service :meta="$meta">
    <x-service-page :title="$date->event->title" :back="route('ticketing.manage.index')" :back-label="__('ticketing.manage_page.back')">
        <x-card data-manage-header>
            <x-service-event-row :occurrence="$date" :href="\App\Support\EventUrl::occurrence($date)" :title="$date->starts_at->timezone($timezone)->format('d/m/Y H:i')" :meta="collect([$venue?->name, $date->locationVenue()?->address])->filter()->implode(' · ')">
                <x-slot:badge>
                    @if ($ended)
                        <x-badge tone="neutral" size="sm">{{ __('ticketing.manage_page.ended_title') }}</x-badge>
                    @else
                        <x-badge :tone="$date->booking_enabled ? 'brand' : 'muted'" size="sm">{{ $date->booking_enabled ? __('ticketing.dashboard_page.booking_on') : __('ticketing.dashboard_page.booking_off') }}</x-badge>
                    @endif
                </x-slot:badge>
                <span>{{ __('ticketing.manage_page.payment_note') }}</span>
            </x-service-event-row>
            <x-slot:footer>
                <h2 class="sr-only">{{ __('ticketing.manage_page.quick') }}</h2>
                <div class="flex flex-wrap gap-3" data-quick-actions>
                @unless ($ended)
                    <x-button size="sm" :href="route('ticketing.manage.scanner', $date)"><x-lucide name="scan-line" class="size-4" />{{ __('ticketing.manage_page.scanner') }}</x-button>
                @endunless
                <x-button size="sm" variant="secondary" :href="route('ticketing.manage.export', $date)"><x-lucide name="download" class="size-4" />{{ __('ticketing.export') }}</x-button>
                <x-button size="sm" variant="secondary" :href="route('ticketing.manage.print', $date)"><x-lucide name="download" class="size-4" />{{ __('ticketing.print.action') }}</x-button>
            </div>
            </x-slot:footer>
        </x-card>

        @if ($ended)
            <x-notice :title="__('ticketing.manage_page.ended_title')">{{ __('ticketing.manage_page.ended') }}</x-notice>
        @elseif (! $ticketingOn)
            <x-notice tone="alert" :title="__('ticketing.manage_page.disabled_title')">{{ __('ticketing.disabled_hint') }}</x-notice>
        @endif

        <x-card :title="__('ticketing.manage_page.numbers')">
            <p class="font-display text-lg font-extrabold text-ink">
                {{ $counts['capacity'] === null ? __('ticketing.manage_page.seats_unlimited', ['used' => $counts['confirmed']]) : __('ticketing.manage_page.seats', ['used' => $counts['confirmed'], 'capacity' => $counts['capacity']]) }}
            </p>
            <p class="text-sm text-ink-muted">{{ $availability['remaining'] === null ? __('ticketing.unlimited') : __('ticketing.remaining', ['count' => $availability['remaining']]) }}</p>
            <x-stat-list data-ticket-statistics :label="__('ticketing.manage_page.numbers')" :items="collect(['confirmed', 'checked_in', 'waitlisted', 'cancelled', 'no_show'])->map(fn (string $key): array => ['label' => __('ticketing.manage_page.stats.'.$key), 'value' => $counts[$key] ?? 0])->all()" />
            @if (($counts['no_show'] ?? 0) > 0 && $presenze > 0)
                <p class="text-sm text-ink-muted">{{ __('ticketing.no_show.rate', ['percent' => (int) round(($counts['no_show'] / $presenze) * 100)]) }} {{ __('ticketing.no_show.hint', ['limit' => config('ticketing.no_show.limit'), 'days' => config('ticketing.no_show.window_days'), 'cap' => max(0, (int) config('ticketing.no_show.limit') - 1)]) }}</p>
            @endif
        </x-card>

        <x-card id="prenotazioni" :title="__('ticketing.manage_page.bookings')" :description="__('ticketing.manage_page.bookings_hint')">
            <form method="GET" data-ticket-search class="flex flex-col gap-3" role="search">
                @if ($status !== null)<input type="hidden" name="status" value="{{ $status }}">@endif
                <x-field name="q" :label="__('ticketing.search')" :value="request('q')" autocomplete="off" />
                <div><x-button type="submit" variant="secondary"><x-lucide name="search" class="size-4" />{{ __('ticketing.filter') }}</x-button></div>
            </form>
            <p data-search-status role="status" class="text-sm text-ink-muted empty:hidden" data-loading="{{ __('ticketing.search_status.loading') }}" data-done="{{ __('ticketing.search_status.done') }}" data-failed="{{ __('ticketing.search_status.failed') }}"></p>
            <div data-ticket-results class="flex flex-col gap-4">
                <x-segmented :label="__('ticketing.manage_page.filter_label')" :items="collect(['all', ...\App\Http\Controllers\TicketingController::BOOKING_FILTERS])->map(fn (string $key): array => [
                    'label' => __('ticketing.manage_page.filters.'.$key),
                    'href' => $filterUrl($key === 'all' ? null : $key).'#prenotazioni',
                    'active' => ($status ?? 'all') === $key,
                    'count' => $filterCounts[$key] ?? 0,
                ])->all()" />
                @forelse ($bookings as $booking)
                    <article data-booking="{{ $booking->id }}" class="flex flex-col gap-3 border-t border-line pt-4">
                        <div class="flex flex-col gap-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="font-display text-base font-extrabold text-ink">{{ __('ticketing.booking_number', ['id' => $booking->id]) }}</h3>
                                <x-badge :tone="$bookingTone($booking)" size="sm">{{ __('ticketing.tickets_page.booking_statuses.'.$booking->status->value) }}</x-badge>
                                @if ($booking->isStaffBooking())<x-badge tone="neutral" size="sm">{{ __('ticketing.guest.badge') }}</x-badge>@endif
                            </div>
                            <p class="text-sm break-words text-ink-muted">{{ collect([$booking->contactEmail(), __('ticketing.manage_page.booked_at', ['date' => $booking->created_at?->timezone($timezone)->format('d/m/Y H:i')]), trans_choice('ticketing.manage_page.tickets', $booking->tickets->count())])->filter()->implode(' · ') }}</p>
                        </div>
                        <ul class="flex flex-col">
                            @foreach ($booking->tickets as $ticket)
                                <li class="flex flex-wrap items-center justify-between gap-3 py-2">
                                    <div class="flex min-w-0 flex-col gap-0.5">
                                        <span class="font-semibold text-ink">{{ $ticket->attendee_name }}</span>
                                        <span class="flex flex-wrap items-center gap-2 text-sm text-ink-muted">#{{ $ticket->id }} <x-badge :tone="$ticketTone($ticket->displayStatus())" size="sm">{{ __('ticketing.statuses.'.$ticket->displayStatus()->value) }}</x-badge></span>
                                    </div>
                                    @unless ($ended)
                                        <div class="flex flex-wrap gap-2">
                                            @if ($ticket->status === \App\Enums\AdmissionStatus::Valid)<form method="POST" action="{{ route('ticketing.manage.checkin', $date) }}">@csrf<input type="hidden" name="code" value="{{ $ticket->code }}"><x-button type="submit" size="sm">{{ __('ticketing.checkin') }}</x-button></form>@endif
                                            @if (! in_array($ticket->status, [\App\Enums\AdmissionStatus::Cancelled, \App\Enums\AdmissionStatus::CheckedIn], true))<form method="POST" action="{{ route('ticketing.manage.cancel', $booking) }}" data-confirm="{{ __('ticketing.confirm_cancel') }}">@csrf<input type="hidden" name="ticket_id" value="{{ $ticket->id }}"><x-button type="submit" variant="secondary" size="sm">{{ __('ticketing.cancel_ticket') }}</x-button></form>@endif
                                        </div>
                                    @endunless
                                </li>
                            @endforeach
                        </ul>
                        @if ($booking->booker_data)
                            <details class="text-sm"><summary class="flex min-h-10 cursor-pointer items-center font-semibold text-brand">{{ __('ticketing.booker') }}</summary><dl class="flex flex-col gap-2 pb-2">
                                @foreach ($booking->booker_data as $field => $value)@if ($value)<div class="flex flex-col gap-0.5"><dt class="text-ink-muted">{{ __('ticketing.fields.'.$field) }}</dt><dd class="whitespace-pre-line text-ink">{{ $value }}</dd></div>@endif @endforeach
                                <div class="flex flex-col gap-0.5"><dt class="text-ink-muted">{{ __('ticketing.privacy_record') }}</dt><dd class="text-ink">{{ $booking->privacy_accepted_at?->timezone($timezone)->format('d/m/Y H:i') ?? '—' }}</dd></div>
                            </dl></details>
                        @endif
                        @if ($booking->booking_answers)
                            <details open class="text-sm"><summary class="flex min-h-10 cursor-pointer items-center font-semibold text-brand">{{ __('ticketing.questions.answers') }}</summary><dl class="flex flex-col gap-2 pb-2" data-booking-answers>
                                @foreach ($booking->booking_answers as $answer)<div class="flex flex-col gap-0.5"><dt class="text-ink-muted">{{ $answer['question'] }}</dt><dd class="whitespace-pre-line text-ink">{{ $answer['answer'] }}</dd></div>@endforeach
                            </dl></details>
                        @endif
                        @if ($booking->status !== \App\Enums\BookingStatus::Cancelled && ! $ended)
                            <details class="text-sm"><summary class="flex min-h-10 cursor-pointer items-center font-semibold text-brand">{{ __('ticketing.cancel') }}</summary>
                                <form method="POST" action="{{ route('ticketing.manage.cancel', $booking) }}" class="flex flex-col gap-3 pb-2" data-confirm="{{ __('ticketing.confirm_cancel') }}">@csrf<x-field name="reason" :label="__('ticketing.reason')" /><div><x-button type="submit" variant="danger" size="sm">{{ __('ticketing.cancel') }}</x-button></div></form>
                            </details>
                        @endif
                    </article>
                @empty
                    <p class="border-t border-line pt-4 text-sm text-ink-muted">{{ request('q') ? __('ticketing.manage_page.empty_search') : ($status !== null ? __('ticketing.manage_page.empty_filter') : __('ticketing.manage_page.empty')) }}</p>
                @endforelse
                {{ $bookings->links() }}
            </div>
        </x-card>

        @unless ($ended)
            <x-card padding="none">
                <details data-manage-section="checkin">
                    <summary class="{{ $summary }}"><span class="flex flex-col gap-1"><span class="font-display text-lg font-extrabold text-ink">{{ __('ticketing.manage_page.checkin_title') }}</span><span class="text-sm text-ink-muted">{{ __('ticketing.manage_page.checkin_hint') }}</span></span><x-lucide name="chevron-down" class="mt-1 size-5 shrink-0 text-ink-muted" /></summary>
                    <div class="border-t border-line px-5 pb-5 sm:px-6">@include('ticketing.scanner-form')</div>
                </details>
            </x-card>

            @if ($ticketingOn)
                <x-card padding="none">
                    <details data-manage-section="settings" @if ($errors->hasAny(['booking_enabled', 'booking_capacity', 'booking_limit', 'booking_opens_at', 'booking_closes_at', 'cancellation_closes_at', 'booking_instructions', 'booking_questions'])) open @endif>
                        <summary class="{{ $summary }}"><span class="flex flex-col gap-1"><span class="font-display text-lg font-extrabold text-ink">{{ __('ticketing.settings') }}</span><span class="text-sm text-ink-muted">{{ __('ticketing.manage_page.settings_hint') }}</span></span><x-lucide name="chevron-down" class="mt-1 size-5 shrink-0 text-ink-muted" /></summary>
                        <form action="{{ route('ticketing.manage.configure', $date) }}" method="POST" class="flex flex-col gap-5 border-t border-line px-5 pt-5 pb-5 sm:px-6">@csrf
                            <label class="flex min-h-12 items-center gap-3 text-sm font-semibold text-ink"><input type="hidden" name="booking_enabled" value="0"><input type="checkbox" name="booking_enabled" value="1" @checked(old('booking_enabled', $date->booking_enabled)) class="size-5 shrink-0 accent-brand"> {{ __('ticketing.enabled') }}</label>
                            <label class="flex min-h-12 items-center gap-3 text-sm font-semibold text-ink"><input type="hidden" name="booking_waitlist" value="0"><input type="checkbox" name="booking_waitlist" value="1" @checked(old('booking_waitlist', $date->booking_waitlist)) class="size-5 shrink-0 accent-brand"> {{ __('ticketing.waitlist') }}</label>
                            <x-field name="booking_capacity" type="number" :label="__('ticketing.capacity')" :hint="__('ticketing.capacity_hint')" :value="$date->booking_capacity" />
                            <x-field name="booking_limit" type="number" :label="__('ticketing.booking_limit')" :value="$date->booking_limit" :required="true" />
                            <p class="text-sm text-ink-muted">{{ __('ticketing.time_hint', ['timezone' => $timezone]) }}</p>
                            @foreach (['booking_opens_at' => 'opens_at', 'booking_closes_at' => 'closes_at', 'cancellation_closes_at' => 'cancellation_closes_at'] as $field => $label)
                                <x-field :name="$field" type="datetime-local" :label="__('ticketing.'.$label)" :value="$date->$field?->timezone($timezone)->format('Y-m-d\TH:i')" />
                            @endforeach
                            <x-field name="booking_instructions" type="textarea" rich :label="__('ticketing.instructions')" :value="$date->booking_instructions" />
                            <fieldset class="flex flex-col gap-3 border-t border-line pt-5"><legend class="sr-only">{{ __('ticketing.form_fields') }}</legend>
                                <h3 class="font-semibold text-ink" aria-hidden="true">{{ __('ticketing.form_fields') }}</h3>
                                <p class="text-sm text-ink-muted">{{ __('ticketing.form_hint') }}</p>
                                @foreach (\App\Services\Ticketing\BookingForm::FIELDS as $field)
                                    <label class="flex flex-col gap-1.5 text-sm font-semibold text-ink">{{ __('ticketing.fields.'.$field) }}
                                        <select name="booking_fields[{{ $field }}]" class="min-h-12 w-full border border-line bg-surface px-3 text-ink">
                                            @foreach (\App\Enums\BookingFieldMode::cases() as $mode)<option value="{{ $mode->value }}" @selected(old('booking_fields.'.$field, $date->booking_fields[$field] ?? 'hidden') === $mode->value)>{{ __('ticketing.field_modes.'.$mode->value) }}</option>@endforeach
                                        </select>
                                    </label>
                                @endforeach
                            </fieldset>
                            @php $questions = app(\App\Services\Ticketing\BookingForm::class)->questions($date); @endphp
                            <fieldset class="flex flex-col gap-4 border-t border-line pt-5" data-booking-questions><legend class="sr-only">{{ __('ticketing.questions.title') }}</legend>
                                <h3 class="font-semibold text-ink" aria-hidden="true">{{ __('ticketing.questions.title') }}</h3>
                                <p class="text-sm text-ink-muted">{{ __('ticketing.questions.hint', ['max' => \App\Services\Ticketing\BookingForm::MAX_QUESTIONS, 'days' => \App\Services\Ticketing\BookingForm::ANSWERS_RETENTION_DAYS]) }}</p>
                                @error('booking_questions')<p role="alert" class="text-sm font-semibold text-alert">{{ $message }}</p>@enderror
                                @for ($i = 0; $i < \App\Services\Ticketing\BookingForm::MAX_QUESTIONS; $i++)
                                    @php $question = $questions[$i] ?? null; @endphp
                                    <div class="flex flex-col gap-3 border border-line bg-canvas p-4">
                                        <input type="hidden" name="booking_questions[{{ $i }}][id]" value="{{ old('booking_questions.'.$i.'.id', $question['id'] ?? '') }}">
                                        <label class="flex flex-col gap-1.5 text-sm font-semibold text-ink">{{ __('ticketing.questions.label') }} {{ $i + 1 }}<input name="booking_questions[{{ $i }}][label]" value="{{ old('booking_questions.'.$i.'.label', $question['label'] ?? '') }}" maxlength="160" class="min-h-12 w-full border border-line bg-surface px-3 font-normal text-ink"></label>
                                        <label class="flex flex-col gap-1.5 text-sm font-semibold text-ink">{{ __('ticketing.questions.type') }}
                                            <select name="booking_questions[{{ $i }}][type]" class="min-h-12 w-full border border-line bg-surface px-3 font-normal text-ink">
                                                @foreach (\App\Enums\BookingQuestionType::cases() as $type)<option value="{{ $type->value }}" @selected(old('booking_questions.'.$i.'.type', $question['type'] ?? 'text') === $type->value)>{{ __('ticketing.questions.types.'.$type->value) }}</option>@endforeach
                                            </select>
                                        </label>
                                        <label class="flex flex-col gap-1.5 text-sm font-semibold text-ink">{{ __('ticketing.questions.options') }}<textarea name="booking_questions[{{ $i }}][options]" rows="3" maxlength="1000" class="w-full border border-line bg-surface p-3 font-normal text-ink">{{ old('booking_questions.'.$i.'.options', implode("\n", $question['options'] ?? [])) }}</textarea></label>
                                        <label class="flex min-h-12 items-center gap-3 text-sm text-ink"><input type="hidden" name="booking_questions[{{ $i }}][required]" value="0"><input type="checkbox" name="booking_questions[{{ $i }}][required]" value="1" @checked(old('booking_questions.'.$i.'.required', $question['required'] ?? false)) class="size-5 accent-brand shrink-0">{{ __('ticketing.questions.required') }}</label>
                                        @error('booking_questions.'.$i.'.options')<p role="alert" class="text-sm font-semibold text-alert">{{ $message }}</p>@enderror
                                    </div>
                                @endfor
                            </fieldset>
                            <x-form-actions><x-button type="submit">{{ __('ticketing.save') }}</x-button></x-form-actions>
                        </form>
                    </details>
                </x-card>
            @endif
        @endunless

        @if (! $ended || $messages->isNotEmpty())
            <x-card padding="none">
                <details data-manage-section="messages" @if ($errors->hasAny(['subject', 'body'])) open @endif>
                    <summary class="{{ $summary }}"><span class="flex flex-col gap-1"><span class="font-display text-lg font-extrabold text-ink">{{ __('ticketing.messages.title') }}</span><span class="text-sm text-ink-muted">{{ __('ticketing.manage_page.messages_hint') }}</span></span><x-lucide name="chevron-down" class="mt-1 size-5 shrink-0 text-ink-muted" /></summary>
                    <div class="flex flex-col gap-5 border-t border-line px-5 pt-5 pb-5 sm:px-6">
                        @unless ($ended)
                            <p class="text-sm text-ink-muted">{{ __('ticketing.messages.hint', ['limit' => config('ticketing.messages.daily_limit')]) }}</p>
                            <form method="POST" action="{{ route('ticketing.manage.message', $date) }}" class="flex flex-col gap-4" data-confirm="{{ __('ticketing.messages.send') }}?">@csrf
                                <x-field name="subject" :label="__('ticketing.messages.subject')" :required="true" maxlength="160" />
                                <x-field name="body" type="textarea" :label="__('ticketing.messages.body')" :required="true" maxlength="2000" />
                                <label class="flex min-h-12 items-center gap-3 text-sm text-ink"><input type="hidden" name="include_waitlisted" value="0"><input type="checkbox" name="include_waitlisted" value="1" @checked(old('include_waitlisted')) class="size-5 accent-brand shrink-0"> {{ __('ticketing.messages.include_waitlisted') }}</label>
                                <x-form-actions><x-button type="submit">{{ __('ticketing.messages.send') }}</x-button></x-form-actions>
                            </form>
                        @endunless
                        @if ($messages->isNotEmpty())
                            <div class="flex flex-col gap-2">
                                <h3 class="font-semibold text-ink">{{ __('ticketing.messages.history') }}</h3>
                                <ul class="flex flex-col gap-3 text-sm">
                                    @foreach ($messages as $message)
                                        <li class="flex flex-col gap-1 border-t border-line pt-3"><strong class="text-ink">{{ $message->subject }}</strong>
                                            <span class="text-ink-muted">{{ __('ticketing.messages.history_line', ['date' => $message->created_at?->timezone($timezone)->format('d/m/Y H:i'), 'sender' => $message->sender?->name ?? '—', 'count' => $message->recipients_count]) }}</span>
                                            <p class="whitespace-pre-line text-ink">{{ $message->body }}</p></li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                </details>
            </x-card>
        @endif

        @unless ($ended)
            <x-card padding="none">
                <details data-manage-section="guest" @if ($errors->hasAny(['names', 'phone', 'note'])) open @endif>
                    <summary class="{{ $summary }}"><span class="flex flex-col gap-1"><span class="font-display text-lg font-extrabold text-ink">{{ __('ticketing.guest.title') }}</span><span class="text-sm text-ink-muted">{{ __('ticketing.manage_page.guest_hint') }}</span></span><x-lucide name="chevron-down" class="mt-1 size-5 shrink-0 text-ink-muted" /></summary>
                    <form method="POST" action="{{ route('ticketing.manage.guest', $date) }}" class="flex flex-col gap-4 border-t border-line px-5 pt-5 pb-5 sm:px-6">@csrf
                        <p class="text-sm text-ink-muted">{{ __('ticketing.guest.hint') }}</p>
                        <x-field name="names" type="textarea" rows="3" :label="__('ticketing.guest.names')" :required="true" />
                        <x-field name="email" type="email" :label="__('ticketing.email')" autocomplete="off" />
                        <x-field name="phone" type="tel" :label="__('ticketing.fields.phone')" autocomplete="off" />
                        <x-field name="note" :label="__('ticketing.guest.note')" />
                        <x-form-actions><x-button type="submit">{{ __('ticketing.guest.save') }}</x-button></x-form-actions>
                    </form>
                </details>
            </x-card>
        @endunless

        <x-card padding="none">
            <details data-manage-section="staff" @if ($errors->has('email') && old('names') === null) open @endif>
                <summary class="{{ $summary }}"><span class="flex flex-col gap-1"><span class="font-display text-lg font-extrabold text-ink">{{ __('decision.staff') }}</span><span class="text-sm text-ink-muted">{{ __('ticketing.manage_page.staff_hint') }}</span></span><x-lucide name="chevron-down" class="mt-1 size-5 shrink-0 text-ink-muted" /></summary>
                <div class="flex flex-col gap-4 border-t border-line px-5 pt-5 pb-5 sm:px-6">
                    <p class="text-sm text-ink-muted">{{ __('decision.staff_hint') }}</p>
                    @unless ($ended)
                        <form method="POST" action="{{ route('ticketing.manage.staff', $date) }}" class="flex flex-col gap-3">@csrf
                            <label for="personale-email" class="text-sm font-semibold text-ink">{{ __('decision.staff_email') }} <span class="text-live" aria-hidden="true">*</span></label>
                            <input id="personale-email" name="email" type="email" required autocomplete="off" class="min-h-12 w-full border border-line bg-surface px-3.5 py-2.5 text-sm text-ink focus:border-brand focus:outline-none">
                            <div><x-button type="submit" variant="secondary">{{ __('decision.staff_add') }}</x-button></div>
                        </form>
                    @endunless
                    @if ($staff !== [])
                        <ul class="flex flex-col">
                            @foreach ($staff as $member)
                                <li class="flex flex-wrap items-center justify-between gap-3 border-t border-line py-3">
                                    <span class="min-w-0 text-sm break-words text-ink">{{ $member['email'] }} · <span class="text-ink-muted">{{ __($member['status'] === 'pending' ? 'decision.staff_pending' : 'decision.staff_active') }}</span></span>
                                    @unless ($ended)
                                        <form method="POST" action="{{ route('ticketing.manage.staff', $date) }}">@csrf<input type="hidden" name="email" value="{{ $member['email'] }}"><input type="hidden" name="remove" value="1"><x-button type="submit" variant="secondary" size="sm">{{ __($member['status'] === 'pending' ? 'decision.staff_withdraw' : 'decision.staff_remove') }}</x-button></form>
                                    @endunless
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </details>
        </x-card>
    </x-service-page>
</x-layouts.app>
