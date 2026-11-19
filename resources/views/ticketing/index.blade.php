{{--
    «I miei biglietti» (`/biglietti`) e la scheda di una prenotazione
    (`/biglietti/prenotazione/{id}`, con `$detail`). L'elenco ha due sezioni,
    Prossimi e Passati e annullati, una card per prenotazione con la
    locandina; la scheda mostra subito i QR, uno per biglietto, e le azioni.

    Sotto impersonificazione si vedono prenotazioni e stato, non QR, PDF e
    link di condivisione: il codice vale come il biglietto al portatore.
--}}
@php
    $impersonating = session()->has(\App\Http\Controllers\Web\ImpersonationController::SESSION_KEY);
    $badgeTone = fn (\App\Models\Booking $booking): string => match ($booking->status) {
        \App\Enums\BookingStatus::Confirmed => 'brand',
        \App\Enums\BookingStatus::Waitlisted => 'soon',
        default => 'muted',
    };
    $ticketTone = fn (\App\Enums\AdmissionStatus $status): string => match ($status) {
        \App\Enums\AdmissionStatus::Valid => 'brand',
        \App\Enums\AdmissionStatus::CheckedIn => 'accent',
        \App\Enums\AdmissionStatus::Waitlisted => 'soon',
        default => 'muted',
    };
    $ended = fn (\App\Models\Booking $booking): bool => $booking->occurrence !== null
        && now()->gte($booking->occurrence->effective_ends_at ?? $booking->occurrence->ends_at ?? $booking->occurrence->starts_at);
@endphp
<x-layouts.app service :meta="$meta">
    @if ($detail)
        @php
            $date = $booking->occurrence;
            $data = \App\Http\Resources\V1\BookingResource::toArray($booking);
            $active = $booking->status !== \App\Enums\BookingStatus::Cancelled;
            $timezone = $date?->event?->city?->timezone ?? config('app.timezone');
        @endphp
        <x-service-page :title="$meta->heading" :back="route('tickets.index')" :back-label="__('ticketing.tickets_page.back')">
            <x-card :title="__('ticketing.tickets_page.event')">
                <x-service-event-row :occurrence="$date" :event="$date?->event" :title="$data['title']" :href="$date === null ? false : null">
                    <x-slot:badge>
                        <x-badge :tone="$ended($booking) && $active ? 'neutral' : $badgeTone($booking)" size="sm">{{ $ended($booking) && $active ? __('ticketing.tickets_page.ended') : __('ticketing.tickets_page.booking_statuses.'.$booking->status->value) }}</x-badge>
                    </x-slot:badge>
                    <span>{{ __('ticketing.booking_number', ['id' => $booking->id]) }} · {{ trans_choice('ticketing.tickets_page.ticket_count', $booking->tickets->count()) }}</span>
                    @if (filled($data['address']))
                        <span>{{ $data['address'] }}</span>
                    @endif
                </x-service-event-row>
                @if ($data['instructions'])
                    <div class="flex flex-col gap-1 border-t border-line pt-4">
                        <h3 class="text-sm font-semibold text-ink">{{ __('ticketing.tickets_page.instructions') }}</h3>
                        <p class="text-sm whitespace-pre-line text-ink-muted">{{ $data['instructions'] }}</p>
                    </div>
                @endif
            </x-card>

            @if ($booking->status === \App\Enums\BookingStatus::Waitlisted)
                <x-notice :title="__('ticketing.queue_position', ['position' => $data['queue_position']])">{{ __('ticketing.waiting_hint') }}</x-notice>
            @endif
            @if ($booking->promotion_expires_at !== null && $booking->status === \App\Enums\BookingStatus::Confirmed)
                {{-- Il posto c'è ma non è ancora suo: finché non conferma resta di chi è in coda. --}}
                <x-notice tone="success" :title="__('ticketing.promotion_pending', ['scadenza' => $booking->promotion_expires_at->timezone($timezone)->format('d/m/Y H:i')])">
                    <form method="POST" action="{{ route('tickets.confirm', $booking) }}">@csrf<x-button type="submit">{{ __('ticketing.promotion_confirm') }}</x-button></form>
                </x-notice>
            @endif

            <x-card id="biglietti" :title="__('ticketing.tickets_page.tickets')" :description="__('ticketing.tickets_page.tickets_hint')">
                @foreach ($booking->tickets as $ticket)
                    @php $status = $ticket->displayStatus(); @endphp
                    <div @class(['flex flex-col gap-4', 'border-t border-line pt-5' => ! $loop->first]) data-ticket>
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <h3 class="font-display text-lg font-extrabold text-ink">{{ $ticket->attendee_name }}</h3>
                            <x-badge :tone="$ticketTone($status)" size="sm">{{ __('ticketing.statuses.'.$status->value) }}</x-badge>
                            <span class="text-sm text-ink-muted">#{{ $ticket->id }}</span>
                        </div>
                        @if ($status === \App\Enums\AdmissionStatus::Valid && $impersonating)
                            <p class="text-sm text-ink-muted">{{ __('ticketing.qr_impersonating') }}</p>
                        @elseif ($status === \App\Enums\AdmissionStatus::Valid)
                            <figure class="flex flex-col gap-2">
                                <div class="w-fit bg-white p-4" role="img" aria-label="{{ __('ticketing.tickets_page.qr_label') }} · {{ __('ticketing.ticket') }} #{{ $ticket->id }}">{!! \SimpleSoftwareIO\QrCode\Facades\QrCode::size(224)->margin(2)->generate($ticket->code) !!}</div>
                                <figcaption class="text-xs text-ink-muted">{{ __('ticketing.qr_hint') }}</figcaption>
                            </figure>
                            <div class="flex flex-wrap gap-3">
                                <x-button variant="secondary" size="sm" :href="route('tickets.pdf', $ticket)"><x-lucide name="download" class="size-4" />{{ __('ticketing.pdf') }}</x-button>
                            </div>
                            @if ($shareUrl = app(\App\Services\Ticketing\TicketSharing::class)->url($ticket))
                                <details class="flex flex-col gap-3" data-ticket-share>
                                    <summary class="flex min-h-12 cursor-pointer items-center font-semibold text-brand">{{ __('ticketing.share.title') }}</summary>
                                    <div class="flex flex-col gap-3 pt-1">
                                        <p class="text-sm text-ink-muted">{{ __('ticketing.share.hint') }}</p>
                                        <input type="text" readonly value="{{ $shareUrl }}" class="min-h-12 w-full min-w-0 border border-line bg-surface px-3 text-sm text-ink" aria-label="{{ __('ticketing.share.title') }}" data-share-url>
                                        <div class="flex flex-wrap gap-3">
                                            <x-button type="button" variant="secondary" size="sm" data-share-copy data-copied="{{ __('ticketing.share.copied') }}">{{ __('ticketing.share.copy') }}</x-button>
                                            <x-button type="button" variant="secondary" size="sm" data-share-native data-share-title="{{ $data['title'] }}" hidden>{{ __('ticketing.share.native') }}</x-button>
                                        </div>
                                        <form method="POST" action="{{ route('tickets.share', $ticket) }}" class="flex flex-col gap-3 border-t border-line pt-4">
                                            @csrf
                                            <p class="font-semibold text-ink">{{ __('ticketing.share.email_title') }}</p>
                                            <p class="text-xs text-ink-muted">{{ __('ticketing.share.email_hint') }}</p>
                                            <x-field name="email" type="email" :label="__('ticketing.email')" autocomplete="off" :required="true" />
                                            <label class="flex items-start gap-3 text-sm text-ink"><input type="checkbox" name="consent" value="1" required class="mt-0.5 size-5 accent-brand shrink-0"> {{ __('ticketing.share.consent') }}</label>
                                            <div><x-button type="submit" variant="secondary">{{ __('ticketing.share.send') }}</x-button></div>
                                        </form>
                                    </div>
                                </details>
                            @endif
                        @endif
                        @if ($data['can_cancel'] && $booking->tickets->count() > 1 && in_array($ticket->status, [\App\Enums\AdmissionStatus::Valid, \App\Enums\AdmissionStatus::Waitlisted], true))
                            <form method="POST" action="{{ route('tickets.cancel', $booking) }}" data-confirm="{{ __('ticketing.confirm_cancel') }}">
                                @csrf<input type="hidden" name="ticket_id" value="{{ $ticket->id }}"><x-button type="submit" variant="secondary" size="sm">{{ __('ticketing.cancel_ticket') }}</x-button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </x-card>

            @if ($data['answers'] !== [])
                <x-card :title="__('ticketing.questions.yours')">
                    <dl class="flex flex-col gap-3 text-sm">
                        @foreach ($data['answers'] as $answer)<div class="flex flex-col gap-0.5"><dt class="text-ink-muted">{{ $answer['question'] }}</dt><dd class="whitespace-pre-line text-ink">{{ $answer['answer'] }}</dd></div>@endforeach
                    </dl>
                </x-card>
            @endif

            @if ($active)
                <x-card :title="__('ticketing.tickets_page.actions')">
                    <div class="flex flex-col gap-2">
                        <p class="text-sm text-ink-muted">{{ __('ticketing.tickets_page.resend_hint') }}</p>
                        <form method="POST" action="{{ route('tickets.resend', $booking) }}">@csrf<x-button type="submit" variant="secondary"><x-lucide name="mail" class="size-4" />{{ __('ticketing.resend') }}</x-button></form>
                    </div>
                    <div class="flex flex-col gap-2 border-t border-line pt-4">
                        @if ($data['can_cancel'])
                            <p class="text-sm text-ink-muted">{{ __('ticketing.tickets_page.cancel_hint') }} {{ __('ticketing.cancellation_until', ['date' => ($date->cancellation_closes_at ?? $date->starts_at)->timezone($timezone)->format('d/m/Y H:i')]) }}</p>
                            <form method="POST" action="{{ route('tickets.cancel', $booking) }}" data-confirm="{{ __('ticketing.confirm_cancel') }}">
                                @csrf<x-button type="submit" variant="danger">{{ __('ticketing.cancel') }}</x-button>
                            </form>
                        @else
                            <p class="text-sm text-ink-muted">{{ __('ticketing.tickets_page.cancellation_closed') }}</p>
                        @endif
                    </div>
                </x-card>
            @endif
        </x-service-page>
    @else
        <x-service-page :title="__('ticketing.title')" :subtitle="__('ticketing.tickets_page.subtitle')" :back="route('account.profile')">
            @if (($noShows ?? 0) > 0)
                <x-notice tone="alert">{{ __('ticketing.no_show.account', ['count' => $noShows, 'limit' => config('ticketing.no_show.limit'), 'days' => config('ticketing.no_show.window_days'), 'cap' => max(0, (int) config('ticketing.no_show.limit') - 1)]) }}</x-notice>
            @endif

            @if ($upcoming->isEmpty() && $past->isEmpty() && $past->currentPage() === 1)
                <x-empty-state :title="__('ticketing.empty')">
                    <x-button :href="route('home')">{{ __('ticketing.browse') }}</x-button>
                </x-empty-state>
            @else
                @foreach ([['key' => 'upcoming', 'items' => $upcoming], ['key' => 'past', 'items' => $past]] as $section)
                    @continue($section['key'] === 'past' && $section['items']->isEmpty())
                    <section aria-labelledby="biglietti-{{ $section['key'] }}" class="flex flex-col gap-4" data-ticket-section="{{ $section['key'] }}">
                        <div class="flex flex-col gap-1">
                            <h2 id="biglietti-{{ $section['key'] }}" class="font-display text-xl leading-tight font-extrabold text-ink">{{ __('ticketing.tickets_page.'.$section['key']) }}</h2>
                            <p class="text-sm text-ink-muted">{{ __('ticketing.tickets_page.'.$section['key'].'_hint') }}</p>
                        </div>
                        @forelse ($section['items'] as $booking)
                            @php
                                $data = \App\Http\Resources\V1\BookingResource::toArray($booking);
                                $isEnded = $ended($booking) && $booking->status !== \App\Enums\BookingStatus::Cancelled;
                                $hasQr = ! $impersonating && $booking->tickets->contains(fn ($ticket) => $ticket->displayStatus() === \App\Enums\AdmissionStatus::Valid);
                            @endphp
                            <x-card as="article" data-booking="{{ $booking->id }}">
                                <x-service-event-row :occurrence="$booking->occurrence" :event="$booking->occurrence?->event" :title="$data['title']" :href="route('tickets.show', $booking)">
                                    <x-slot:badge>
                                        <x-badge :tone="$isEnded ? 'neutral' : $badgeTone($booking)" size="sm">{{ $isEnded ? __('ticketing.tickets_page.ended') : __('ticketing.tickets_page.booking_statuses.'.$booking->status->value) }}</x-badge>
                                    </x-slot:badge>
                                    <span>{{ __('ticketing.booking_number', ['id' => $booking->id]) }} · {{ trans_choice('ticketing.tickets_page.ticket_count', $booking->tickets->count()) }}</span>
                                </x-service-event-row>

                                <ul class="flex flex-col gap-1.5 border-t border-line pt-4 text-sm">
                                    @foreach ($booking->tickets as $ticket)
                                        <li class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                                            <span class="font-semibold text-ink">{{ $ticket->attendee_name }}</span>
                                            <span class="text-ink-muted">{{ __('ticketing.statuses.'.$ticket->displayStatus()->value) }}</span>
                                        </li>
                                    @endforeach
                                </ul>

                                @if ($booking->status === \App\Enums\BookingStatus::Waitlisted)
                                    <x-notice :title="__('ticketing.queue_position', ['position' => $data['queue_position']])">{{ __('ticketing.waiting_hint') }}</x-notice>
                                @endif
                                @if ($booking->promotion_expires_at !== null && $booking->status === \App\Enums\BookingStatus::Confirmed)
                                    <x-notice tone="success" :title="__('ticketing.promotion_pending', ['scadenza' => $booking->promotion_expires_at->timezone($booking->occurrence?->event?->city?->timezone ?? config('app.timezone'))->format('d/m/Y H:i')])">
                                        <form method="POST" action="{{ route('tickets.confirm', $booking) }}">@csrf<x-button type="submit">{{ __('ticketing.promotion_confirm') }}</x-button></form>
                                    </x-notice>
                                @endif
                                @if ($data['answers'] !== [])
                                    <details class="text-sm">
                                        <summary class="flex min-h-12 cursor-pointer items-center font-semibold text-brand">{{ __('ticketing.questions.yours') }}</summary>
                                        <dl class="flex flex-col gap-3 pb-2">
                                            @foreach ($data['answers'] as $answer)<div class="flex flex-col gap-0.5"><dt class="text-ink-muted">{{ $answer['question'] }}</dt><dd class="whitespace-pre-line text-ink">{{ $answer['answer'] }}</dd></div>@endforeach
                                        </dl>
                                    </details>
                                @endif

                                <x-slot:footer>
                                    @if ($hasQr)
                                        <x-button size="sm" :href="route('tickets.show', $booking).'#biglietti'"><x-lucide name="qr-code" class="size-4" />{{ __('ticketing.show_qr') }}</x-button>
                                    @else
                                        <x-button size="sm" variant="secondary" :href="route('tickets.show', $booking)">{{ __('ticketing.tickets_page.details') }}</x-button>
                                    @endif
                                </x-slot:footer>
                            </x-card>
                        @empty
                            <x-card tone="muted">
                                <p class="text-sm text-ink-muted">{{ __('ticketing.tickets_page.upcoming_empty') }}</p>
                                <div><x-button variant="secondary" size="sm" :href="route('home')">{{ __('ticketing.browse') }}</x-button></div>
                            </x-card>
                        @endforelse
                        @if ($section['key'] === 'past')
                            {{ $past->links() }}
                        @endif
                    </section>
                @endforeach
            @endif
        </x-service-page>
    @endif
</x-layouts.app>
