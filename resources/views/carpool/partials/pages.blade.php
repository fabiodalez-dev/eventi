{{-- Precedente / successiva di un elenco paginato; `page_key` è il nome del parametro. --}}
@if ($page > 1 || $has_more)
    <div class="flex flex-wrap gap-3">
        @if ($page > 1)
            <x-button variant="secondary" size="sm" :href="request()->fullUrlWithQuery([($page_key ?? 'page') => $page - 1])"><x-lucide name="arrow-left" class="size-4" />{{ __('pagination.previous') }}</x-button>
        @endif
        @if ($has_more)
            <x-button variant="secondary" size="sm" :href="request()->fullUrlWithQuery([($page_key ?? 'page') => $page + 1])">{{ __('pagination.next') }}<x-lucide name="arrow-right" class="size-4" /></x-button>
        @endif
    </div>
@endif
