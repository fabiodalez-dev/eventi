<x-layouts.app :narrow="true" :meta="$meta">
    <div class="mx-auto flex max-w-3xl flex-col gap-8">
        <h1 class="text-hero">{{ __('ticketing.title') }}</h1>
        @include('ticketing.errors')
        @forelse ($bookings as $booking)
            @php($data = \App\Http\Resources\V1\BookingResource::toArray($booking))
            <section class="border-t-2 border-line pt-5">
                <p class="text-sm text-brand">{{ __('ticketing.booking_number', ['id' => $booking->id]) }} · {{ __('ticketing.statuses.'.$booking->status->value) }}</p>
                <h2 class="mt-2 text-2xl font-extrabold uppercase">{{ $data['title'] }}</h2>
                <p class="mt-2">{{ $booking->occurrence?->starts_at?->timezone(auth()->user()->timezone)->format('d/m/Y H:i') }} · {{ $data['venue'] }}</p>
                <p class="text-sm text-ink-muted">{{ $data['address'] }}</p>
                <form method="POST" action="{{ route('tickets.resend', $booking) }}" class="mt-3">@csrf<x-button type="submit" variant="ghost">{{ __('ticketing.resend') }}</x-button></form>
                @if ($data['instructions'])<p class="mt-3 whitespace-pre-line">{{ $data['instructions'] }}</p>@endif
                @if ($booking->status === \App\Enums\BookingStatus::Waitlisted)<p class="mt-4 text-sm">{{ __('ticketing.waiting_hint') }}</p>@endif
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
