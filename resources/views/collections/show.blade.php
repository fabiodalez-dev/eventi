{{-- `/raccolte/{token}`: una raccolta condivisa, per chi ha il link. --}}
<x-layouts.app :meta="$meta" :narrow="true">
    <p class="text-eyebrow">{{ $collection->ownerLabel() ? __('collections.shared_by', ['name' => $collection->ownerLabel()]) : __('collections.shared_anonymous') }}</p>
    <h1 class="text-hero">{{ $collection->name }}</h1>
    <p class="my-3 text-ink-muted">{{ filled($collection->description) ? $collection->description : __('collections.shared_lead', ['app' => config('app.name')]) }}</p>
    @if ($isOwner)
        <a href="{{ route('account.collections.show', $collection) }}" class="inline-flex min-h-12 items-center underline">{{ __('collections.manage') }}</a>
    @endif

    @forelse ($occurrences as $occurrence)
        <div class="border-t-2 border-line py-4">
            <x-event-card :occurrence="$occurrence" />
            @if (filled($notes[$occurrence->getKey()] ?? null))
                <p class="mt-2 text-sm"><strong>{{ __('collections.note') }}:</strong> {{ $notes[$occurrence->getKey()] }}</p>
            @endif
        </div>
    @empty
        <p class="text-sm text-ink-muted">{{ __('collections.empty') }}</p>
    @endforelse
</x-layouts.app>
