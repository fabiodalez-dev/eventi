{{--
    La griglia per categoria (§11.2, punto 9).

    Compaiono solo le categorie che hanno davvero date in programma: il
    controller le conta con una sola interrogazione aggregata. Una casella che
    porta a una lista vuota è un contenitore vuoto con un passaggio in mezzo
    (§8.6).
--}}
@props(['categories'])

<div {{ $attributes->class(['grid gap-3 sm:grid-cols-2 lg:grid-cols-3']) }}>
    @foreach ($categories as $entry)
        @php
            $category = $entry['category'];
            $color = is_string($category->color) && preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $category->color) === 1
                ? $category->color
                : null;
        @endphp

        <a
            href="{{ route('events.category', $category) }}"
            class="group flex items-center justify-between gap-3 rounded-card bg-surface px-4 py-3.5 ring-1 ring-line transition hover:ring-line-strong"
        >
            <span class="flex min-w-0 items-center gap-2.5">
                <span
                    aria-hidden="true"
                    class="size-2.5 shrink-0 rounded-pill bg-brand"
                    @if ($color) style="background-color: {{ $color }}" @endif
                ></span>

                <span class="truncate font-semibold text-ink">{{ $category->name }}</span>
            </span>

            <span class="shrink-0 text-sm text-ink-subtle">{{ $entry['count'] }}</span>
        </a>
    @endforeach
</div>
