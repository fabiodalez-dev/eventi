{{--
    La chat di un passaggio: con chi si parla, la serata, i messaggi (i propri
    a destra), il campo per scrivere e le due opzioni che valgono solo per sé,
    archiviare e silenziare, che si applicano subito con un tocco. Lo script
    `carpool.js` aggiunge i messaggi nuovi con le stesse classi.
--}}
@php
    $other = $ride['is_driver'] ? $ride['requester']['name'] : $offer['driver']['name'];
    $bubble = ['mine' => 'ml-10 self-end bg-brand-soft text-on-brand-soft sm:ml-16', 'theirs' => 'mr-10 self-start border border-line bg-surface-sunken text-ink sm:mr-16'];
@endphp
<x-layouts.app service :meta="$meta">
    <x-service-page :title="__('carpool.web.chat_title', ['name' => $other])" :subtitle="__('carpool.chat_context')"
        :back="route('carpool.chats')" :back-label="__('carpool.web.back_chats')" :tabs="$tabs" :tabs-label="__('carpool.web.tabs.label')">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" :href="$ride['url']">{{ __('carpool.web.chat_ride') }}</x-button>
        </x-slot:actions>

        @include('carpool.partials.event')

        <x-card padding="none" data-ride-chat data-url="{{ route('carpool.chat', $chat_id) }}" data-send="{{ route('carpool.chat.action', [$chat_id, 'send']) }}"
            data-preferences="{{ route('carpool.chat.action', [$chat_id, 'preferences']) }}" data-readonly="{{ __('carpool.chat_readonly') }}" data-error="{{ __('carpool.errors.generic') }}"
            data-bubble-mine="{{ $bubble['mine'] }}" data-bubble-theirs="{{ $bubble['theirs'] }}">
            <div class="svc-card__head--flush flex items-center gap-3 px-5 py-4 sm:px-6">
                <x-avatar :name="$other" />
                <div class="flex min-w-0 flex-col">
                    <h2 class="font-display text-lg leading-tight font-extrabold text-ink">{{ $other }}</h2>
                    <p class="text-sm text-ink-muted">{{ $ride['status_label'] }}@if ($archived) · {{ __('carpool.web.chats_archived') }}@endif</p>
                </div>
            </div>
            <div class="flex flex-col gap-3 px-5 py-4 sm:px-6">
                @if (count($messages) >= 50)
                    <div><x-button type="button" variant="ghost" size="sm" data-chat-older>{{ __('carpool.older') }}</x-button></div>
                @endif
                <div data-chat-messages role="log" aria-live="polite" aria-relevant="additions" class="flex max-h-[55dvh] flex-col gap-3 overflow-y-auto p-1">
                    @foreach ($messages as $message)
                        <article data-message-id="{{ $message['id'] }}" class="{{ $message['mine'] ? $bubble['mine'] : $bubble['theirs'] }} px-4 py-3">
                            <p class="text-sm whitespace-pre-wrap break-words">{{ $message['body'] }}</p>
                            <time datetime="{{ $message['created_at'] }}" class="mt-1 block text-xs opacity-75">{{ \Carbon\CarbonImmutable::parse($message['created_at'])->setTimezone('Europe/Rome')->format('d/m H:i') }}</time>
                        </article>
                    @endforeach
                </div>
                <p data-chat-error role="alert" class="text-sm text-live empty:hidden"></p>
            </div>
            <div class="svc-card__footer px-5 py-4 sm:px-6">
                @if ($writable)
                    <form data-chat-send method="post" action="{{ route('carpool.chat.action', [$chat_id, 'send']) }}" class="flex flex-col gap-3">
                        @include('carpool.partials.key')
                        @include('carpool.partials.field', ['name' => 'body', 'id' => 'chat-messaggio', 'label' => __('carpool.message'), 'type' => 'textarea', 'required' => true, 'maxlength' => 2000, 'rows' => 3, 'value' => old('body')])
                        <div><x-button type="submit"><x-lucide name="arrow-right" class="size-4" />{{ __('carpool.send') }}</x-button></div>
                    </form>
                @else
                    <x-notice>{{ __('carpool.chat_readonly') }}</x-notice>
                @endif
            </div>
        </x-card>

        <x-card :title="__('carpool.web.chat_options')" :description="__('carpool.web.chat_options_help')" data-chat-options>
            <div class="flex flex-wrap gap-3">
                <form action="{{ route('carpool.chat.action', [$chat_id, 'preferences']) }}" method="post">
                    @csrf
                    <input type="hidden" name="archived" value="{{ $archived ? 0 : 1 }}">
                    <x-button type="submit" variant="secondary" size="sm">{{ __($archived ? 'carpool.web.chat_unarchive' : 'carpool.web.chat_archive') }}</x-button>
                </form>
                <form action="{{ route('carpool.chat.action', [$chat_id, 'preferences']) }}" method="post">
                    @csrf
                    <input type="hidden" name="muted" value="{{ $muted ? 0 : 1 }}">
                    <x-button type="submit" variant="secondary" size="sm" aria-pressed="{{ $muted ? 'true' : 'false' }}">{{ __($muted ? 'carpool.web.chat_unmute' : 'carpool.web.chat_mute') }}</x-button>
                </form>
            </div>
        </x-card>

        @include('carpool.partials.report')
    </x-service-page>
</x-layouts.app>
