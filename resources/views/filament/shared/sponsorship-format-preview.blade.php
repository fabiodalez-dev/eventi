{{-- Anteprima schematica di dove compare un formato: un telefono con la parte sponsorizzata nel colore d'accento. --}}
@php
    $line = 'block h-1 rounded-full bg-gray-300 dark:bg-white/20';
    $hot = 'block rounded-sm bg-primary-500 dark:bg-primary-400';
@endphp
<span aria-hidden="true" class="relative flex h-24 w-14 shrink-0 flex-col gap-1 overflow-hidden rounded-lg border-2 border-gray-300 bg-white p-1.5 dark:border-white/20 dark:bg-gray-900">
    @switch($placement)
        @case('home_hero')
            <span class="{{ $hot }} h-8"></span>
            <span class="{{ $line }} w-10/12"></span>
            <span class="{{ $line }} w-8/12"></span>
            <span class="block h-4 rounded-sm bg-gray-200 dark:bg-white/10"></span>
            <span class="{{ $line }} w-9/12"></span>
            @break
        @case('home_card')
            <span class="block h-4 rounded-sm bg-gray-200 dark:bg-white/10"></span>
            <span class="{{ $line }} w-9/12"></span>
            <span class="{{ $hot }} h-6"></span>
            <span class="{{ $line }} w-10/12"></span>
            <span class="block h-4 rounded-sm bg-gray-200 dark:bg-white/10"></span>
            @break
        @case('list_top')
            <span class="{{ $hot }} h-3.5"></span>
            @foreach (range(1, 5) as $row)
                <span class="block h-2.5 rounded-sm bg-gray-200 dark:bg-white/10"></span>
            @endforeach
            @break
        @default
            <span class="block flex-1 rounded-sm bg-gray-100 dark:bg-white/5">
                <span class="mx-auto mt-2 block size-2 rounded-full bg-gray-400 dark:bg-white/30"></span>
            </span>
            <span class="{{ $hot }} h-7"></span>
    @endswitch
</span>
