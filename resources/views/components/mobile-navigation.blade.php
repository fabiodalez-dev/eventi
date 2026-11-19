{{--
    La barra in basso del telefono: sette destinazioni, solo icone, come
    nell'app.

    Il nome di ogni voce sta in `aria-label` (lo legge lo screen reader) e in
    `title` (compare tenendo premuto o passando il puntatore): a 375 px sette
    etichette non ci stanno senza diventare illeggibili, e un'icona sola per
    voce è anche ciò che fa l'app. Ogni voce prende un settimo della barra
    (almeno 48 px a 375 px), alta 56 px; quella attiva ha una pastiglia attorno
    all'icona. «Cerca» resta anche se la ricerca è nella testata: l'app non ce
    l'ha, e le due barre devono essere uguali.

    Le icone sono quelle di Lucide, scritte qui per non dipendere da un
    componente che le carica una a una.
--}}
@php
    $items = [
        ['url' => route('home'), 'label' => __('ui.nav.home'), 'active' => request()->routeIs('home', 'city.home'), 'icon' => '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>'],
        ['url' => route('events.index'), 'label' => __('ui.nav.events'), 'active' => request()->routeIs('events.*', 'city.events.*'), 'icon' => '<path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/>'],
        ['url' => route('calendar.index'), 'label' => __('ui.nav.calendar'), 'active' => request()->routeIs('calendar.*', 'city.calendar.*'), 'icon' => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/><path d="M8 18h.01"/><path d="M12 18h.01"/>'],
        ['url' => route('map.index'), 'label' => __('ui.nav.map'), 'active' => request()->routeIs('map.*', 'city.map.*'), 'icon' => '<path d="M14.106 5.553a2 2 0 0 0 1.788 0l3.659-1.83A1 1 0 0 1 21 4.619v12.764a1 1 0 0 1-.553.894l-4.553 2.277a2 2 0 0 1-1.788 0l-4.212-2.106a2 2 0 0 0-1.788 0l-3.659 1.83A1 1 0 0 1 3 19.381V6.618a1 1 0 0 1 .553-.894l4.553-2.277a2 2 0 0 1 1.788 0z"/><path d="M15 5.764v15"/><path d="M9 3.236v15"/>'],
        ['url' => route('search'), 'label' => __('ui.nav.search'), 'active' => request()->routeIs('search', 'city.search'), 'icon' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>'],
        ['url' => route('account.saved'), 'label' => __('account.nav.saved'), 'active' => request()->routeIs('account.saved*'), 'icon' => '<path d="m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16z"/>', 'saved' => true],
        ['url' => route('account.profile'), 'label' => __('account.nav.profile'), 'active' => request()->routeIs('account.profile*', 'login', 'account.register*', 'account.password.*'), 'icon' => '<circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/>', 'community' => true],
    ];
@endphp
@auth <meta name="saved-state-url" content="{{ route('account.saved.state') }}"> @endauth
<nav data-mobile-navigation data-scroll-navigation @if(request()->routeIs('map.*', 'login', 'account.*')) data-navigation-always @endif aria-label="{{ __('ui.nav.label') }}" class="mobile-nav fixed inset-x-0 bottom-0 z-[9000] grid grid-cols-7 border-t-2 border-line bg-canvas pb-[env(safe-area-inset-bottom)] lg:hidden">
    @foreach ($items as $item)
        <a
            href="{{ $item['url'] }}"
            aria-label="{{ $item['label'] }}"
            title="{{ $item['label'] }}"
            @if ($item['active']) aria-current="page" @endif
            @if ($item['saved'] ?? false) data-saved-mobile-opener aria-haspopup="dialog" @endif
            class="mobile-nav__item flex h-14 min-w-0 items-center justify-center focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-accent"
        >
            <span class="mobile-nav__pill relative grid h-8 w-11 place-items-center">
                <svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">{!! $item['icon'] !!}</svg>
                @if (($item['community'] ?? false) && auth()->check())
                    <span data-community-count="total" hidden class="mobile-nav__badge absolute -top-1 -right-1 min-w-4 px-1 text-center text-[10px] leading-4 font-bold"></span>
                @endif
            </span>
        </a>
    @endforeach
</nav>
