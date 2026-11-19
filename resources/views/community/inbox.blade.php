{{--
    Gli avvisi: ogni avviso è una riga con il suo titolo vero («Prenotazione
    confermata», non il nome della pagina), da quanto tempo, il distintivo
    «Nuovo» finché non è letto e la locandina quando riguarda un evento. La
    riga intera porta dove l'avviso rimanda.
--}}
@php
    $unified = app(\App\Services\Carpool\UnifiedNotifications::class);
    $items = $unified->presentations($notifications->getCollection());
    $hasUnread = collect($items)->contains('unread', true);
@endphp
<x-layouts.app service :meta="$meta">
    <x-service-page
        :title="$meta->heading"
        :subtitle="__('community.inbox_lead')"
        :back="route('account.profile')"
        :tabs="\App\Services\Community\CommunityNavigation::tabs('inbox')"
        :tabs-label="__('community.nav')"
    >
        @if ($hasUnread)
            <x-slot:actions>
                <form method="post" action="{{ route('community.inbox.read') }}">
                    @csrf
                    <input type="hidden" name="through" value="{{ $unified->watermark(auth()->user()) }}">
                    <x-button variant="secondary" size="sm" type="submit"><x-lucide name="check" class="size-4" />{{ __('community.read_all') }}</x-button>
                </form>
            </x-slot:actions>
        @endif

        @if ($items === [])
            <x-empty-state :title="__('community.no_notifications')" :description="__('community.no_notifications_help')" />
        @else
            <x-card padding="none" data-inbox>
                @foreach ($items as $item)
                    <x-card.row :href="$item['url']" :title="$item['title']" :badge="$item['unread'] ? __('community.new') : null" :description="$item['body']" data-notification="{{ $item['id'] }}">
                        <x-slot:leading>
                            @if ($item['event'] !== null && \App\Support\Poster::imageSet($item['event']) !== null)
                                <span class="svc-poster svc-poster--sm" aria-hidden="true"><x-event-artwork :event="$item['event']" sizes="56px" /></span>
                            @else
                                {{-- Larga quanto la locandina: i titoli restano allineati. --}}
                                <span class="flex w-14 justify-center" aria-hidden="true">
                                    <span class="svc-avatar size-10"><x-lucide :name="$item['event'] !== null ? 'calendar' : 'mail'" class="size-5" /></span>
                                </span>
                            @endif
                        </x-slot:leading>
                        <time class="text-xs text-ink-subtle" datetime="{{ $item['created_at']->toIso8601String() }}" title="{{ $item['created_at']->timezone(app(\App\Support\CurrentCity::class)->timezone())->format('d/m/Y H:i') }}">{{ $item['created_at']->locale('it')->diffForHumans() }}</time>
                    </x-card.row>
                @endforeach
            </x-card>
            <x-pagination :paginator="$notifications" />
        @endif
    </x-service-page>
</x-layouts.app>
