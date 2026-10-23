{{-- «Avvisami per nuovi eventi come questi»: salva i filtri correnti come
     ricerca da rifare ogni ora. Chi non ha un account vede il collegamento
     all'accesso, con ritorno a questa stessa pagina. --}}
@props(['filters' => []])
@php $filters = array_filter($filters, fn ($value) => $value !== null && $value !== ''); @endphp
@if ($filters !== [])
    @auth
        <form method="POST" action="{{ route('account.searches.store') }}" class="inline-flex" data-saved-search>
            @csrf
            @foreach ($filters as $key => $value)
                <input type="hidden" name="filters[{{ $key }}]" value="{{ $value }}">
            @endforeach
            <button type="submit" class="ui-action inline-flex min-h-12 items-center gap-2 px-3 text-sm font-semibold underline" title="{{ __('search.saved.cta_hint') }}">
                <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
                {{ __('search.saved.cta') }}
            </button>
        </form>
    @else
        <a href="{{ route('login', ['redirect' => url()->full()]) }}" class="ui-action inline-flex min-h-12 items-center px-3 text-sm font-semibold underline">{{ __('search.saved.login') }}</a>
    @endauth
@endif
