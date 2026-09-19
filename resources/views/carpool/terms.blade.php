<x-layouts.app :narrow="true" :meta="$meta"><div class="mx-auto w-full max-w-4xl">@include('carpool.nav')
<article class="prose max-w-3xl text-ink">{!! \Illuminate\Support\Str::markdown($terms, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</article>
</div></x-layouts.app>
