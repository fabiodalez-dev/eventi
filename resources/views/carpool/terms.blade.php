<x-layouts.app :meta="$meta">@include('carpool.nav')<div class="w-full max-w-3xl">
<article class="prose max-w-3xl text-ink">{!! \Illuminate\Support\Str::markdown($terms, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</article>
</div></x-layouts.app>
