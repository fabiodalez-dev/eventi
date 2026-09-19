<article class="space-y-3 border-b border-line py-6">
    <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="font-semibold">{{ $ride['requester']['name'] }}</h2><span class="text-sm">{{ $ride['status_label'] }}</span></div>
    <p>{{ __('carpool.requested_seats', ['count' => $ride['seats']]) }}</p>
    @if($ride['companions'])<p class="text-sm text-ink-muted">{{ __('carpool.group_help') }}</p>@endif
    @if($ride['note'])<p class="whitespace-pre-line break-words">{{ $ride['note'] }}</p>@endif
    <div class="flex flex-wrap gap-3">
        @if($ride['can_decide'])
            @foreach(['accept', 'decline'] as $action)<form method="post" action="{{ route('carpool.action', $action) }}">@include('carpool.partials.key')<input type="hidden" name="request_id" value="{{ $ride['id'] }}"><x-button type="submit" :variant="$action === 'accept' ? 'primary' : 'secondary'">{{ __('carpool.'.$action) }}</x-button></form>@endforeach
        @endif
        @if($ride['can_withdraw'])<form method="post" action="{{ route('carpool.action', 'withdraw') }}">@include('carpool.partials.key')<input type="hidden" name="request_id" value="{{ $ride['id'] }}"><x-button type="submit" variant="secondary">{{ __('carpool.withdraw') }}</x-button></form>@endif
        @if($ride['chat_id'])<x-button :href="route('carpool.chat', $ride['chat_id'])">{{ __('carpool.open_chat') }}</x-button>@endif
        <a class="inline-flex min-h-12 items-center text-sm underline" href="{{ $ride['url'] }}">{{ __('carpool.details') }}</a>
    </div>
</article>
