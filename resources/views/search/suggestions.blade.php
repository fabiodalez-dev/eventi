@if (mb_strlen($term) >= 3)
    @foreach (['events' => $events, 'venues' => $venues, 'organizers' => $organizers, 'tags' => $tags] as $group => $items)
        @if ($items->isNotEmpty())
            <section class="border-b border-line p-3">
                <h2 class="mb-1 font-display text-xs font-extrabold uppercase tracking-wider text-accent">{{ $group === 'organizers' ? 'Organizzatori' : __('search.groups.'.$group) }}</h2>
                <ul>
                    @foreach ($items as $item)
                        <li>
                            <a class="flex items-center gap-3 px-2 py-2 text-sm text-ink hover:bg-surface focus:bg-surface focus:outline-2 focus:outline-accent" href="{{ match ($group) {
                                'events' => \App\Support\EventUrl::occurrence($item),
                                'venues' => route('venues.show', ['slug' => $item->slug]),
                                'organizers' => route('organizers.show', ['slug' => $item->slug]),
                                'tags' => route('events.tag', ['tag' => $item->slug]),
                            } }}">
                                @if ($group === 'events')<x-event-artwork :event="$item->event" class="!w-12 shrink-0" />@endif
                                <span class="min-w-0">
                                <span class="block font-semibold">{{ $group === 'events' ? $item->event->title : $item->name }}</span>
                                @if ($group === 'events')
                                    <span class="block text-xs text-ink-muted">{{ $item->effectiveVenue()?->name ?? $city->name }} · {{ $item->starts_at->timezone($city->timezone)->format('d/m') }}@unless ($item->is_all_day) {{ $item->starts_at->timezone($city->timezone)->format('H:i') }}@endunless</span>
                                @endif
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    @endforeach
    @if ($events->isEmpty() && $venues->isEmpty() && $tags->isEmpty() && $organizers->isEmpty())
        <p class="p-4 text-sm text-ink-muted">{{ __('search.empty.title', ['query' => $term]) }}</p>
    @endif
    <a class="block p-3 font-display text-sm font-bold text-accent hover:underline focus:underline" href="{{ route('search', ['q' => $term]) }}">{{ __('search.live.all') }}</a>
@endif
