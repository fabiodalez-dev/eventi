@props(['event', 'id' => 'descrizione-evento'])
@if (filled($event->description))
    <section aria-labelledby="{{ $id }}" {{ $attributes->class(['flex flex-col gap-3']) }}>
        <h2 id="{{ $id }}" class="font-display text-[clamp(1.25rem,1.8vw,1.75rem)] leading-none font-extrabold tracking-[-0.03em] uppercase">{{ __('events.detail.description') }}</h2>
        <div class="flex max-w-prose flex-col gap-3 text-ink-muted">
            @foreach (preg_split('/\R{2,}/', (string) $event->description) ?: [] as $paragraph)
                @if (trim($paragraph) !== '')
                    <p>{{ $paragraph }}</p>
                @endif
            @endforeach
        </div>
    </section>
@endif
