<x-layouts.app :narrow="true" :meta="$meta">@include('community.nav')<h1 class="text-hero">{{ $meta->heading }}</h1>
    <form method="post" action="{{ route('community.inbox.read') }}" class="my-6">@csrf<input type="hidden" name="through" value="{{ app(\App\Services\Carpool\UnifiedNotifications::class)->watermark(auth()->user()) }}"><x-button variant="secondary" type="submit">{{ __('community.read_all') }}</x-button></form>
    <ul class="divide-y divide-line">@forelse($notifications as $notification)<li class="py-5"><p class="font-semibold">{{ $notification->data['title'] ?? __('community.inbox') }} @unless($notification->read_at)<span class="text-xs text-brand">{{ __('community.new') }}</span>@endunless</p><p class="mt-2 text-sm text-ink-muted">{{ $notification->data['body'] ?? '' }}</p>
        @php($link = app(\App\Services\Carpool\UnifiedNotifications::class)->destination($notification->data)['url'] ?? null)
        @if(is_string($link) && str_starts_with($link, url('/').'/'))<a class="mt-3 inline-flex min-h-11 items-center text-sm underline" href="{{ $link }}">{{ __('community.notifications.open') }}</a>@endif</li>
        @empty<li class="py-10 text-ink-muted">{{ __('community.no_notifications') }}</li>@endforelse</ul>
    <x-pagination :paginator="$notifications" />
</x-layouts.app>
