@php
    $items = [
        ['url' => route('home'), 'label' => __('ui.nav.home'), 'active' => request()->routeIs('home'), 'path' => 'M3 10l9-7 9 7 M5 9v12h14V9 M9 21v-8h6v8'],
        ['url' => route('events.index'), 'label' => __('ui.nav.events'), 'active' => request()->routeIs('events.*'), 'path' => 'M4 5h16v16H4z M8 3v4 M16 3v4 M4 11h16'],
        ['url' => route('map.index'), 'label' => __('ui.nav.map'), 'active' => request()->routeIs('map.*'), 'path' => 'M9 18l-6 3V6l6-3 6 3 6-3v15l-6 3-6-3z M9 3v15 M15 6v15'],
        ['url' => route('search'), 'label' => __('ui.nav.search'), 'active' => request()->routeIs('search'), 'path' => 'M21 21l-6-6 M17 10a7 7 0 1 1-14 0 7 7 0 0 1 14 0'],
        ['url' => route('account.saved'), 'label' => __('account.nav.saved'), 'active' => request()->routeIs('account.saved*'), 'path' => 'M6 3h12v18l-6-4-6 4z'],
        ['url' => route('account.profile'), 'label' => __('account.nav.profile'), 'active' => request()->routeIs('account.profile*', 'login', 'account.register*', 'account.password.*'), 'path' => 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0 M4 21v-2a8 8 0 0 1 16 0v2'],
    ];
@endphp
@auth <meta name="saved-state-url" content="{{ route('account.saved.state') }}"> @endauth
<nav data-mobile-navigation data-scroll-navigation @if(request()->routeIs('map.*', 'login', 'account.*')) data-navigation-always @endif aria-label="{{ __('ui.nav.label') }}" class="fixed inset-x-0 bottom-0 z-[9000] grid grid-cols-6 border-t-2 border-line bg-canvas pb-[env(safe-area-inset-bottom)] lg:hidden">
    @foreach ($items as $item)
        <a href="{{ $item['url'] }}" @if ($item['active']) aria-current="page" @endif
           class="flex min-h-16 min-w-0 flex-col items-center justify-center gap-1 px-1 py-2 text-[0.625rem] font-extrabold uppercase tracking-wide focus-visible:outline-2 focus-visible:outline-accent {{ $item['active'] ? 'bg-accent text-on-accent' : 'text-ink-muted hover:text-accent' }}">
            <svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $item['path'] }}" /></svg>
            <span>{{ $item['label'] }}</span>
        </a>
    @endforeach
</nav>
