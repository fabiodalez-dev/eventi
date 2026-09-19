{{--
    Gli ultimi avvisi nella tendina della campanella. Arriva per conto suo,
    chiesto dallo script all'apertura, e si innesta nel pannello: niente
    testata né titolo di documento, solo l'elenco. Il collegamento a tutti gli
    avvisi sta nel pannello stesso, così resta anche se questa richiesta fallisce.
--}}
@php($unified = app(\App\Services\Carpool\UnifiedNotifications::class))
@if($notifications->isEmpty())
    <p class="px-4 py-6 text-sm text-ink-muted">{{ __('community.no_notifications') }}</p>
@else
    <ul class="divide-y divide-line">
        @foreach($notifications as $notification)
            @php($link = $unified->destination($notification->data)['url'] ?? null)
            @php($link = is_string($link) && str_starts_with($link, url('/').'/') ? $link : route('community.inbox'))
            <li>
                <a href="{{ $link }}" class="block px-4 py-3 transition-colors hover:bg-surface focus-visible:bg-surface">
                    <span class="flex items-start justify-between gap-3">
                        <span @class(['text-sm leading-snug', 'font-semibold text-ink' => ! $notification->read_at, 'text-ink-muted' => $notification->read_at])>{{ $notification->data['title'] ?? __('community.inbox') }}</span>
                        @unless($notification->read_at)<span class="mt-1.5 size-2 shrink-0 rounded-full bg-accent" aria-label="{{ __('community.new') }}"></span>@endunless
                    </span>
                    @if(filled($notification->data['body'] ?? null))<span class="mt-1 line-clamp-2 text-xs text-ink-muted">{{ $notification->data['body'] }}</span>@endif
                    <time class="mt-1 block text-xs text-ink-subtle" datetime="{{ $notification->created_at->toIso8601String() }}">{{ $notification->created_at->locale('it')->diffForHumans() }}</time>
                </a>
            </li>
        @endforeach
    </ul>
@endif
