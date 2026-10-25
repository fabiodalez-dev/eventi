<x-layouts.app :narrow="true" :meta="$meta">
    <div class="mx-auto flex max-w-3xl flex-col gap-8">
        <h1 class="text-hero">{{ __('ticketing.title') }}</h1>
        @include('ticketing.errors')
        @if (($noShows ?? 0) > 0)
            <p class="border-2 border-line bg-canvas p-4 text-sm">{{ __('ticketing.no_show.account', ['count' => $noShows, 'limit' => config('ticketing.no_show.limit'), 'days' => config('ticketing.no_show.window_days')]) }}</p>
        @endif
        @forelse ($bookings as $booking)
            @php($data = \App\Http\Resources\V1\BookingResource::toArray($booking))
            <section class="border-t-2 border-line pt-5">
                <p class="text-sm text-brand">{{ __('ticketing.booking_number', ['id' => $booking->id]) }} · {{ __('ticketing.statuses.'.$booking->status->value) }}</p>
                <h2 class="mt-2 text-2xl font-extrabold uppercase">{{ $data['title'] }}</h2>
                <p class="mt-2">{{ $booking->occurrence?->starts_at?->timezone(auth()->user()->timezone)->format('d/m/Y H:i') }} · {{ $data['venue'] }}</p>
                <p class="text-sm text-ink-muted">{{ $data['address'] }}</p>
                <form method="POST" action="{{ route('tickets.resend', $booking) }}" class="mt-3">@csrf<x-button type="submit" variant="ghost">{{ __('ticketing.resend') }}</x-button></form>
                @if ($data['instructions'])<p class="mt-3 whitespace-pre-line">{{ $data['instructions'] }}</p>@endif
                @if ($data['answers'] !== [])
                    <details class="mt-3"><summary class="cursor-pointer py-3 text-brand">{{ __('ticketing.questions.yours') }}</summary><dl class="grid gap-3 text-sm">
                        @foreach ($data['answers'] as $answer)<div><dt class="text-ink-muted">{{ $answer['question'] }}</dt><dd class="whitespace-pre-line">{{ $answer['answer'] }}</dd></div>@endforeach
                    </dl></details>
                @endif
                @if ($booking->status === \App\Enums\BookingStatus::Waitlisted)
                    <p class="mt-4 text-sm">{{ __('ticketing.waiting_hint') }}</p>
                    <p class="mt-2 text-sm text-ink-muted">{{ __('ticketing.queue_position', ['position' => $data['queue_position']]) }}</p>
                @endif
                @if ($booking->promotion_expires_at !== null && $booking->status === \App\Enums\BookingStatus::Confirmed)
                    {{-- Il posto c'è ma non è ancora suo: finché non conferma resta di chi è in coda. --}}
                    <div role="status" class="mt-4 flex flex-col gap-3 border-l-4 border-accent bg-surface p-4 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm">{{ __('ticketing.promotion_pending', ['scadenza' => $booking->promotion_expires_at->timezone(auth()->user()->timezone)->format('d/m/Y H:i')]) }}</p>
                        <form method="POST" action="{{ route('tickets.confirm', $booking) }}">@csrf<x-button type="submit">{{ __('ticketing.promotion_confirm') }}</x-button></form>
                    </div>
                @endif
                @foreach ($booking->tickets as $ticket)
                    <div class="mt-5 border border-line p-4">
                        <h3 class="font-bold">{{ $ticket->attendee_name }}</h3>
                        <p class="text-sm text-ink-muted">#{{ $ticket->id }} · {{ __('ticketing.statuses.'.$ticket->displayStatus()->value) }}</p>
                        @if ($ticket->displayStatus() === \App\Enums\AdmissionStatus::Valid)
                            <details class="mt-4"><summary class="cursor-pointer py-3 font-bold text-brand">{{ __('ticketing.show_qr') }}</summary>
                                <div class="my-3 w-fit bg-white p-4" role="img" aria-label="{{ __('ticketing.ticket') }} #{{ $ticket->id }}">{!! \SimpleSoftwareIO\QrCode\Facades\QrCode::size(224)->margin(2)->generate($ticket->code) !!}</div>
                                <p class="text-xs text-ink-muted">{{ __('ticketing.qr_hint') }}</p>
                            </details>
                            <x-button variant="secondary" class="mt-3 min-h-12" :href="route('tickets.pdf', $ticket)">{{ __('ticketing.pdf') }}</x-button>
                            @if ($shareUrl = app(\App\Services\Ticketing\TicketSharing::class)->url($ticket))
                                <details class="mt-3" data-ticket-share>
                                    <summary class="cursor-pointer py-3 font-bold text-brand">{{ __('ticketing.share.title') }}</summary>
                                    <p class="text-sm text-ink-muted">{{ __('ticketing.share.hint') }}</p>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <input type="text" readonly value="{{ $shareUrl }}" class="min-h-12 min-w-0 flex-1 border border-line bg-surface px-3 text-sm" aria-label="{{ __('ticketing.share.title') }}" data-share-url>
                                        <x-button type="button" variant="secondary" data-share-copy data-copied="{{ __('ticketing.share.copied') }}">{{ __('ticketing.share.copy') }}</x-button>
                                        <x-button type="button" variant="secondary" data-share-native data-share-title="{{ $data['title'] }}" hidden>{{ __('ticketing.share.native') }}</x-button>
                                    </div>
                                    <form method="POST" action="{{ route('tickets.share', $ticket) }}" class="mt-4 grid gap-3">
                                        @csrf
                                        <p class="font-semibold">{{ __('ticketing.share.email_title') }}</p>
                                        <p class="text-xs text-ink-muted">{{ __('ticketing.share.email_hint') }}</p>
                                        <x-field name="email" type="email" :label="__('ticketing.email')" autocomplete="off" :required="true" />
                                        <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="consent" value="1" required class="mt-1"> {{ __('ticketing.share.consent') }}</label>
                                        <x-button type="submit" variant="secondary" class="justify-self-start">{{ __('ticketing.share.send') }}</x-button>
                                    </form>
                                </details>
                            @endif
                        @endif
                        @if ($data['can_cancel'] && in_array($ticket->status, [\App\Enums\AdmissionStatus::Valid, \App\Enums\AdmissionStatus::Waitlisted], true))
                            <form method="POST" action="{{ route('tickets.cancel', $booking) }}" class="mt-3" data-confirm="{{ __('ticketing.confirm_cancel') }}">
                                @csrf<input type="hidden" name="ticket_id" value="{{ $ticket->id }}"><x-button type="submit" variant="ghost" class="min-h-12">{{ __('ticketing.cancel_ticket') }}</x-button>
                            </form>
                        @endif
                    </div>
                @endforeach
                @if ($data['can_cancel'] && $booking->tickets->count() > 1 && $booking->status !== \App\Enums\BookingStatus::Cancelled)
                    <form method="POST" action="{{ route('tickets.cancel', $booking) }}" class="mt-3" data-confirm="{{ __('ticketing.confirm_cancel') }}">
                        @csrf<x-button type="submit" variant="secondary">{{ __('ticketing.cancel') }}</x-button>
                    </form>
                @endif
            </section>
        @empty
            <p>{{ __('ticketing.empty') }}</p><x-button :href="route('home')">{{ __('ticketing.browse') }}</x-button>
        @endforelse
        {{ $bookings->links() }}
    </div>
</x-layouts.app>
