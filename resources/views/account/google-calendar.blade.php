<x-layouts.app :meta="$meta" :narrow="true">
    <header class="mb-8">
        <h1 class="text-hero">{{ __('google_calendar.title') }}</h1>
        <p class="mt-4 max-w-prose text-ink-muted">{{ __('google_calendar.lead') }}</p>
        <p class="mt-3 text-sm text-ink-muted break-words">{{ __('google_calendar.account', ['email' => auth()->user()->email]) }}</p>
    </header>
    @if(session('google_calendar_message'))
        <p role="status" class="mb-6 border-2 border-accent p-4">{{ session('google_calendar_message') }}</p>
    @endif
    @if($errors->any())<p role="alert" class="mb-6 border-2 border-line p-4">{{ $errors->first() }}</p>@endif
    @if(!$configured)
        <p role="status" class="mb-6 border-2 border-line p-4">{{ __('google_calendar.unconfigured') }}</p>
    @elseif($connection?->enabled)
        <section class="mb-8 border-y-2 border-line py-5">
            <h2 class="text-section">{{ __('google_calendar.connected') }}</h2>
            <p class="mt-2 text-sm text-ink-muted">{{ $connection->synced_at ? __('google_calendar.last_sync', ['count' => $connection->event_count, 'date' => $connection->synced_at->timezone(app(\App\Support\CurrentCity::class)->timezone())->format('d/m/Y H:i')]) : __('google_calendar.pending') }}</p>
            @if($connection->calendar_id)
                <a class="inline-flex min-h-12 items-center py-3 underline" href="{{ 'https://calendar.google.com/calendar/u/0/r?cid='.rawurlencode(base64_encode($connection->calendar_id)) }}" target="_blank" rel="noopener noreferrer">{{ __('google_calendar.open') }}</a>
            @endif
            <a class="inline-flex min-h-12 items-center p-3 underline" href="{{ route('google-calendar.index') }}">{{ __('google_calendar.refresh') }}</a>
        </section>
    @endif
    @if($connection?->error_code)<p role="alert" class="mb-6 border-2 border-line p-4">{{ __('google_calendar.'.($connection->error_code === \App\Enums\GoogleCalendarError::Authorization ? 'authorization' : 'error')) }}</p>@endif
    <section class="space-y-4">
        <h2 class="text-section">{{ __('google_calendar.filters') }}</h2>
        <p>{{ $categories->isEmpty() ? __('google_calendar.categories') : $categories->join(', ') }}</p>
        <p>{{ $venue ?: __('google_calendar.venue') }} · {{ __('google_calendar.horizon', ['days' => $selection['days'] ?? 30]) }} · {{ !empty($selection['free']) ? __('google_calendar.free') : __('google_calendar.prices') }}</p>
        <p class="text-sm text-ink-muted">{{ __('google_calendar.limit', ['count' => config('feeds.max_items')]) }}</p>
        <a class="inline-flex min-h-12 items-center underline" href="{{ route('feeds.wizard', [...$selection, 'step' => 1]) }}">{{ __('google_calendar.change') }}</a>
        @if($configured)
            <p class="max-w-prose text-ink-muted">{{ __('google_calendar.scope') }}</p>
            <p class="max-w-prose text-sm text-ink-muted">{{ __('google_calendar.timing') }}</p>
            <form method="POST" action="{{ route($connection?->enabled ? 'google-calendar.update' : 'google-calendar.connect') }}">
                @csrf
                @if($connection?->enabled) @method('PATCH') @endif
                @foreach($selection['categories'] ?? [] as $slug)<input type="hidden" name="categories[]" value="{{ $slug }}">@endforeach
                <input type="hidden" name="days" value="{{ $selection['days'] ?? 30 }}">
                <input type="hidden" name="venue" value="{{ $selection['venue'] ?? '' }}">
                <input type="hidden" name="free" value="{{ !empty($selection['free']) ? '1' : '0' }}">
                <x-button type="submit">{{ __($connection?->enabled ? 'google_calendar.update' : 'google_calendar.connect') }}</x-button>
            </form>
        @endif
    </section>
    @if($connection && ($connection->enabled || $connection->refresh_token))
        <section class="mt-10 border-t-2 border-line pt-6">
            <p class="mb-4 max-w-prose text-sm text-ink-muted">{{ __('google_calendar.disconnect_help') }}</p>
            <form method="POST" action="{{ route('google-calendar.disconnect') }}">@csrf @method('DELETE')
                <x-button type="submit" variant="secondary">{{ __('google_calendar.disconnect') }}</x-button>
            </form>
        </section>
    @endif
</x-layouts.app>
