@props([
    'paginator',
    /* "Da 1 a 24 di 143 risultati" sotto ai controlli */
    'summary' => false,
])

@php
    /* Nessuna pagina, nessun contenitore: la barra semplicemente non esiste. */
    $hasPages = $paginator->hasPages();

    $isNumbered = $paginator instanceof \Illuminate\Pagination\LengthAwarePaginator;

    /* `elements()` del paginatore è protetto: la finestra di numeri si compone
       dalla stessa `UrlWindow` che usa Laravel per le proprie viste. */
    $elements = [];

    if ($isNumbered) {
        $window = \Illuminate\Pagination\UrlWindow::make($paginator->onEachSide(1));

        $elements = array_filter([
            $window['first'],
            is_array($window['slider']) ? __('common.ellipsis') : null,
            $window['slider'],
            is_array($window['last']) ? __('common.ellipsis') : null,
            $window['last'],
        ]);
    }

    $link = 'inline-flex min-w-10 items-center justify-center border border-line bg-surface px-3.5 py-2 text-sm font-semibold text-ink transition hover:border-line-strong hover:bg-surface-sunken';
    $disabled = 'inline-flex min-w-10 items-center justify-center border border-line px-3.5 py-2 text-sm font-semibold text-ink-subtle';
    $current = 'inline-flex min-w-10 items-center justify-center bg-brand px-3.5 py-2 text-sm font-semibold text-on-brand';
@endphp

@if ($hasPages)
    <nav {{ $attributes->class(['mt-8']) }} role="navigation" aria-label="{{ __('ui.pagination.label') }}">
        <div class="flex flex-wrap items-center justify-center gap-2">
            @if ($paginator->previousPageUrl())
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="{{ $link }}">
                    <x-lucide name="arrow-left" class="size-3.5" />&nbsp;{{ __('ui.pagination.previous') }}
                </a>
            @else
                <span class="{{ $disabled }}" aria-disabled="true">
                    <x-lucide name="arrow-left" class="size-3.5" />&nbsp;{{ __('ui.pagination.previous') }}
                </span>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="px-1 text-ink-subtle" aria-hidden="true">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="{{ $current }}" aria-current="page" aria-label="{{ __('ui.pagination.current_page', ['page' => $page]) }}">
                                {{ $page }}
                            </span>
                        @else
                            <a href="{{ $url }}" class="{{ $link }}" aria-label="{{ __('ui.pagination.go_to_page', ['page' => $page]) }}">
                                {{ $page }}
                            </a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->nextPageUrl())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="{{ $link }}">
                    {{ __('ui.pagination.next') }}&nbsp;<x-lucide name="arrow-right" class="size-3.5" />
                </a>
            @else
                <span class="{{ $disabled }}" aria-disabled="true">
                    {{ __('ui.pagination.next') }}&nbsp;<x-lucide name="arrow-right" class="size-3.5" />
                </span>
            @endif
        </div>

        @if ($summary && $isNumbered)
            <p class="mt-3 text-center text-sm text-ink-subtle">
                {{ __('ui.pagination.showing', [
                    'first' => $paginator->firstItem(),
                    'last' => $paginator->lastItem(),
                    'total' => $paginator->total(),
                ]) }}
            </p>
        @endif
    </nav>
@endif
