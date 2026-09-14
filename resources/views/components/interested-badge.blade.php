@props(['occurrence'])
@php($count = $occurrence->interestedCount())
<span data-interest-id="{{ $occurrence->getKey() }}" data-interest-count="{{ $count }}"
    data-interest-singular="{{ trans_choice('events.interested', 1, ['count' => '__COUNT__']) }}"
    data-interest-plural="{{ trans_choice('events.interested', 2, ['count' => '__COUNT__']) }}"
    @if ($count === 0) hidden @endif
    class="inline-flex w-fit text-xs leading-snug text-ink-muted"
    aria-live="polite">
    <span data-interest-text>{{ trans_choice('events.interested', $count, ['count' => $count]) }}</span>
</span>
