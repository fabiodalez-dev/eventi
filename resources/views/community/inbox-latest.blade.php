{{--
    Gli ultimi avvisi nella tendina della campanella. Arriva per conto suo,
    chiesto dallo script all'apertura, e si innesta nel pannello: niente
    testata né titolo di documento, solo l'elenco. Il collegamento a tutti gli
    avvisi sta nel pannello stesso, così resta anche se questa richiesta fallisce.
    Titolo e destinazione vengono dalla stessa presentazione della pagina.
--}}
@php($items = app(\App\Services\Carpool\UnifiedNotifications::class)->presentations($notifications))
@if ($items === [])
    <p class="px-4 py-6 text-sm text-ink-muted">{{ __('community.no_notifications') }}</p>
@else
    <ul class="divide-y divide-line">
        @foreach ($items as $item)
            <li>
                <a href="{{ $item['url'] ?? route('community.inbox') }}" class="block px-4 py-3 transition-colors hover:bg-surface focus-visible:bg-surface">
                    <span class="flex items-start justify-between gap-3">
                        <span @class(['text-sm leading-snug', 'font-semibold text-ink' => $item['unread'], 'text-ink-muted' => ! $item['unread']])>{{ $item['title'] }}</span>
                        @if ($item['unread'])<span class="mt-1.5 size-2 shrink-0 rounded-full bg-accent" aria-label="{{ __('community.new') }}"></span>@endif
                    </span>
                    @if (filled($item['body']))<span class="mt-1 line-clamp-2 text-xs text-ink-muted">{{ $item['body'] }}</span>@endif
                    <time class="mt-1 block text-xs text-ink-subtle" datetime="{{ $item['created_at']->toIso8601String() }}">{{ $item['created_at']->locale('it')->diffForHumans() }}</time>
                </a>
            </li>
        @endforeach
    </ul>
@endif
