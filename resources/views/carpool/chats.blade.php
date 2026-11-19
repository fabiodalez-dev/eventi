{{--
    Messaggi: le chat dei passaggi accettati, attive o archiviate, con la
    persona dall'altra parte, la serata e i messaggi da leggere.
--}}
<x-layouts.app service :meta="$meta">
    <x-service-page :title="__('carpool.messages')" :subtitle="__('carpool.web.chats_subtitle')" :back="route('account.profile')" :tabs="$tabs" :tabs-label="__('carpool.web.tabs.label')">
        <x-segmented :label="__('carpool.web.chats_filter')" :items="[
            ['label' => __('carpool.web.chats_active'), 'href' => route('carpool.chats'), 'active' => ! $archived],
            ['label' => __('carpool.web.chats_archived'), 'href' => route('carpool.chats', ['archived' => 1]), 'active' => $archived],
        ]" />

        @if ($chats === [])
            <x-empty-state :title="__($archived ? 'carpool.web.empty_archived' : 'carpool.web.empty_chats')" :description="__('carpool.empty_messages')">
                <x-button variant="secondary" :href="route('carpool.index')">{{ __('carpool.web.back_mine') }}</x-button>
            </x-empty-state>
        @else
            <div class="flex flex-col gap-3" data-carpool-chats>
                @foreach ($chats as $chat)
                    @php($other = $chat['ride']['is_driver'] ? $chat['ride']['requester']['name'] : $chat['offer']['driver']['name'])
                    <x-card padding="sm" as="article" data-chat-item="{{ $chat['id'] }}">
                        <x-service-event-row :occurrence="$occurrences[$chat['offer']['occurrence_id']] ?? null" :title="$chat['offer']['event_title']" size="sm"
                            :href="$chat['readable'] ? route('carpool.chat', $chat['id']) : $chat['ride']['url']">
                            <x-slot:badge>
                                @if ($chat['unread'])<x-badge tone="live" size="sm">{{ trans_choice('carpool.web.unread', $chat['unread'], ['count' => $chat['unread']]) }}</x-badge>@endif
                            </x-slot:badge>
                            <span class="flex items-center gap-2 font-semibold text-ink"><x-avatar :name="$other" size="sm" />{{ $other }}</span>
                            <span>{{ $chat['offer']['leg_label'] }}@if ($chat['offer']['departure_label']) · {{ $chat['offer']['departure_label'] }}@endif · {{ $chat['ride']['status_label'] }}</span>
                            <x-slot:actions>
                                @if ($chat['readable'])
                                    <x-button size="sm" :href="route('carpool.chat', $chat['id'])"><x-lucide name="message-circle" class="size-4" />{{ __('carpool.open_chat') }}</x-button>
                                @else
                                    <p class="text-sm text-ink-muted">{{ __('carpool.chat_hidden') }}</p>
                                @endif
                            </x-slot:actions>
                        </x-service-event-row>
                    </x-card>
                @endforeach
            </div>
            @include('carpool.partials.pages')
        @endif
    </x-service-page>
</x-layouts.app>
