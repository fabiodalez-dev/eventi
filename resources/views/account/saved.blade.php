{{--
    Le date salvate (§15.3).

    La finestra la decide il motore: `upcoming()` di norma, `past()` per
    l'archivio. Qui non si ricalcola che cosa sia passato — è la regola che
    vale per ogni lista del prodotto (§8.1).
--}}
<x-layouts.app :meta="$meta">
    <header class="flex flex-col gap-2">
        <h1 class="text-hero text-ink">{{ $meta->heading }}</h1>
        <p class="text-sm text-ink-muted">{{ __('account.saved.lead') }}</p>

        <a
            class="self-start text-sm font-semibold text-brand hover:underline"
            href="{{ $past ? route('account.saved') : route('account.saved', ['passate' => 1]) }}"
        >
            {{ $past ? __('account.saved.show_upcoming') : __('account.saved.show_past') }}
        </a>
    </header>

    @if ($occurrences->total() > 0)
        <div class="mt-6" data-results>
            <x-event-grid :occurrences="$occurrences->getCollection()" :adaptive="false" />
        </div>

        <div class="mt-8" data-pagination>
            <x-pagination :paginator="$occurrences" :summary="true" />
        </div>
    @else
        <x-empty-state
            class="mt-8"
            :title="__('account.saved.empty_title')"
            :description="__('account.saved.empty_body')"
        >
            <a
                href="{{ route('events.index') }}"
                class="rounded-pill bg-brand px-4 py-2.5 text-sm font-semibold text-on-brand transition hover:bg-brand-strong"
            >
                {{ __('events.title') }}
            </a>
        </x-empty-state>
    @endif
</x-layouts.app>
