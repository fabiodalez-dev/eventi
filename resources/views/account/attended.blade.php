<x-layouts.app :meta="$meta" :narrow="true">
    <h1 class="text-hero">{{ $meta->heading }}</h1>
    <p class="my-4 text-ink-muted">{{ __('account.attended.lead') }}</p>
    @forelse ($dates as $date)
        @php $row = \App\Http\Controllers\Web\Account\AttendedController::toArray($date); @endphp
        <article class="border-t-2 border-line py-4">
            <p class="text-sm text-ink-muted">{{ $date->starts_at->timezone(auth()->user()->timezone ?: config('app.timezone'))->translatedFormat('l j F Y') }}</p>
            <h2 class="mt-1 font-display text-xl font-extrabold uppercase">
                @if ($row['url'])<a href="{{ $row['url'] }}" class="underline">{{ $row['title'] }}</a>@else {{ $row['title'] }} @endif
            </h2>
            <p class="mt-1 flex flex-wrap gap-x-3 text-sm">
                @if ($row['venue'])<a href="{{ route('venues.show', $row['venue']['slug']) }}" class="inline-flex min-h-12 items-center underline">{{ $row['venue']['name'] }}</a>@endif
                @if ($row['organizer'])<a href="{{ route('organizers.show', $row['organizer']['slug']) }}" class="inline-flex min-h-12 items-center underline">{{ $row['organizer']['name'] }}</a>@endif
                <span class="inline-flex min-h-12 items-center text-ink-muted">{{ trans_choice('account.attended.entries', $row['checked_in'], ['count' => $row['checked_in']]) }}</span>
            </p>
        </article>
    @empty
        <p class="text-sm text-ink-muted">{{ __('account.attended.empty') }}</p>
    @endforelse
    {{ $dates->links() }}
</x-layouts.app>
