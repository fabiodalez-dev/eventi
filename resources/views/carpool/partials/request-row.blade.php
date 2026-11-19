{{--
    Una richiesta ricevuta, nella pagina dell'offerta del conducente: chi
    chiede, quanti posti, la nota e — se è ancora in attesa — Accetta e
    Rifiuta. Dopo la scelta si resta sull'offerta. `$item` arriva da
    `RideResource::request()`.
--}}
<div class="svc-row flex flex-col gap-3 px-5 py-4 sm:px-6" data-request-row="{{ $item['id'] }}">
    <div class="flex min-w-0 items-start gap-3">
        <x-avatar :name="$item['requester']['name']" />
        <div class="flex min-w-0 flex-1 flex-col gap-1">
            <div class="flex flex-wrap items-center gap-2">
                <span class="font-semibold text-ink">{{ $item['requester']['name'] }}</span>
                <x-badge :tone="$item['status'] === 'accepted' ? 'brand' : ($item['status'] === 'pending' ? 'soon' : 'muted')" size="sm">{{ $item['status_label'] }}</x-badge>
            </div>
            <span class="text-sm text-ink-muted">{{ trans_choice('carpool.web.seats_requested', $item['seats'], ['count' => $item['seats']]) }}@if ($item['companions']) · {{ __('carpool.group_help') }}@endif</span>
            @if ($item['note'])<p class="text-sm whitespace-pre-line break-words text-ink">{{ $item['note'] }}</p>@endif
        </div>
    </div>
    <div class="flex flex-wrap gap-2 pl-[3.25rem]">
        @if ($item['can_decide'])
            @foreach (['accept', 'decline'] as $decision)
                <form method="post" action="{{ route('carpool.action', $decision) }}">
                    @include('carpool.partials.key')
                    <input type="hidden" name="request_id" value="{{ $item['id'] }}">
                    <x-button type="submit" size="sm" :variant="$decision === 'accept' ? 'primary' : 'secondary'">
                        <x-lucide :name="$decision === 'accept' ? 'check' : 'x'" class="size-4" />{{ __('carpool.'.$decision) }}
                    </x-button>
                </form>
            @endforeach
        @endif
        @if ($item['chat_id'] && $item['status'] === 'accepted')
            <x-button size="sm" :href="route('carpool.chat', $item['chat_id'])"><x-lucide name="message-circle" class="size-4" />{{ __('carpool.web.write_to', ['name' => $item['requester']['name']]) }}</x-button>
        @elseif ($item['chat_id'])
            <x-button size="sm" variant="secondary" :href="route('carpool.chat', $item['chat_id'])">{{ __('carpool.open_chat') }}</x-button>
        @endif
        <x-button size="sm" variant="ghost" :href="$item['url']">{{ __('carpool.web.open_request') }}</x-button>
    </div>
</div>
