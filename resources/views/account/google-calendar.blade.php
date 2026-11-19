{{--
    Il calendario Google: lo stato del collegamento, cosa ci finisce dentro e
    come cambiarlo, e in fondo lo scollegamento. Una card per ciascuna cosa.
--}}
<x-layouts.app service :meta="$meta">
    <x-service-page :title="__('google_calendar.title')" :subtitle="__('google_calendar.lead')" :back="route('account.profile')">
        @if (session('google_calendar_message'))
            <x-notice tone="success"><p class="m-0">{{ session('google_calendar_message') }}</p></x-notice>
        @endif
        @if (! $configured)
            <x-notice><p class="m-0">{{ __('google_calendar.unconfigured') }}</p></x-notice>
        @endif
        @if ($connection?->error_code)
            <x-notice tone="alert"><p class="m-0">{{ __('google_calendar.'.($connection->error_code === \App\Enums\GoogleCalendarError::Authorization ? 'authorization' : 'error')) }}</p></x-notice>
        @endif

        @if ($configured && $connection?->enabled)
            <x-card :title="__('google_calendar.connected')" tone="accent" :description="$connection->synced_at ? __('google_calendar.last_sync', ['count' => $connection->event_count, 'date' => $connection->synced_at->timezone(app(\App\Support\CurrentCity::class)->timezone())->format('d/m/Y H:i')]) : __('google_calendar.pending')">
                <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                    @if ($connection->calendar_id)
                        <x-button variant="secondary" :block="true" :href="'https://calendar.google.com/calendar/u/0/r?cid='.rawurlencode(base64_encode($connection->calendar_id))" target="_blank" rel="noopener noreferrer">
                            <x-lucide name="external-link" class="size-4" />
                            {{ __('google_calendar.open') }}
                        </x-button>
                    @endif
                    <x-button variant="secondary" :block="true" :href="route('google-calendar.index')">{{ __('google_calendar.refresh') }}</x-button>
                </div>
            </x-card>
        @endif

        <x-card :title="__('google_calendar.filters')" :description="__('google_calendar.account', ['email' => auth()->user()->email])">
            <dl class="m-0 flex flex-col gap-3">
                @foreach ([
                    'categories' => $categories->isEmpty() ? __('google_calendar.categories') : $categories->join(', '),
                    'venue' => $venue ?: __('google_calendar.venue'),
                    'horizon' => __('google_calendar.horizon', ['days' => $selection['days'] ?? 30]),
                    'prices' => ! empty($selection['free']) ? __('google_calendar.free') : __('google_calendar.prices'),
                ] as $key => $value)
                    <div class="flex flex-col gap-0.5">
                        <dt class="text-xs font-semibold tracking-[0.06em] text-ink-muted uppercase">{{ __('account.google_calendar.'.$key) }}</dt>
                        <dd class="m-0 font-semibold break-words text-ink">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
            <p class="m-0 text-sm text-ink-muted">{{ __('google_calendar.limit', ['count' => config('feeds.max_items')]) }}</p>
            <div><x-button variant="secondary" :href="route('feeds.wizard', [...$selection, 'step' => 1])">{{ __('google_calendar.change') }}</x-button></div>
        </x-card>

        @if ($configured)
            <x-card as="form" method="POST" :action="route($connection?->enabled ? 'google-calendar.update' : 'google-calendar.connect')" :title="__($connection?->enabled ? 'google_calendar.update' : 'google_calendar.connect')" :description="__('google_calendar.scope')">
                @csrf
                @if ($connection?->enabled) @method('PATCH') @endif
                @foreach ($selection['categories'] ?? [] as $slug)<input type="hidden" name="categories[]" value="{{ $slug }}">@endforeach
                <input type="hidden" name="days" value="{{ $selection['days'] ?? 30 }}">
                <input type="hidden" name="venue" value="{{ $selection['venue'] ?? '' }}">
                <input type="hidden" name="free" value="{{ ! empty($selection['free']) ? '1' : '0' }}">
                <p class="m-0 text-sm text-ink-muted">{{ __('google_calendar.timing') }}</p>
                <x-form-actions>
                    <x-button type="submit">{{ __($connection?->enabled ? 'google_calendar.update' : 'google_calendar.connect') }}</x-button>
                </x-form-actions>
            </x-card>
        @endif

        @if ($connection && ($connection->enabled || $connection->refresh_token))
            <x-card tone="alert" :title="__('google_calendar.disconnect')" :description="__('google_calendar.disconnect_help')">
                <form method="POST" action="{{ route('google-calendar.disconnect') }}">
                    @csrf @method('DELETE')
                    <x-button type="submit" variant="danger">{{ __('google_calendar.disconnect') }}</x-button>
                </form>
            </x-card>
        @endif
    </x-service-page>
</x-layouts.app>
