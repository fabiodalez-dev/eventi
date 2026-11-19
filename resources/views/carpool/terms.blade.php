{{--
    Le regole dei passaggi (scheda «Aiuto»). Il testo arriva in Markdown dal
    file della versione in vigore: il suo titolo diventa l'h1 della testata e
    la riga della versione il sottotitolo, il resto va nel corpo, in una card.
--}}
@php
    $lines = preg_split('/\R/', trim($terms)) ?: [];
    $heading = isset($lines[0]) && str_starts_with($lines[0], '# ') ? trim(substr(array_shift($lines), 2)) : __('carpool.web.terms_title');
    while ($lines !== [] && trim($lines[0]) === '') {
        array_shift($lines);
    }
    $versionLine = isset($lines[0]) && ! str_starts_with($lines[0], '#') && trim($lines[0]) !== '' ? trim(array_shift($lines)) : null;
    $body = \Illuminate\Support\Str::markdown(implode("\n", $lines), ['html_input' => 'strip', 'allow_unsafe_links' => false]);
@endphp
<x-layouts.app service :meta="$meta">
    <x-service-page :title="$heading" :subtitle="$versionLine" :tabs="$tabs" :tabs-label="__('carpool.web.tabs.label')">
        @include('carpool.partials.help-nav')
        <x-card data-carpool-terms>
            <div class="rich-text text-sm sm:text-base">{!! $body !!}</div>
        </x-card>
    </x-service-page>
</x-layouts.app>
