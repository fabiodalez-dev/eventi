<x-layouts.app :meta="$meta" :structured-data="$structuredData">
    <p class="text-eyebrow">Organizzatore</p>
    <h1 class="text-hero">{{ $organizer->name }}</h1>
    <div class="my-6 flex flex-wrap items-center gap-4">
        <x-follow-button :type="\App\Enums\FollowableType::Organizer" :id="$organizer->id" />
    </div>
    @auth
        @if($follow = auth()->user()->follows()->where('followable_type', 'organizer')->where('followable_id', $organizer->id)->first())
            <form method="POST" action="{{ route('account.follows.store') }}" class="my-6 space-y-3">
                @csrf
                <input type="hidden" name="type" value="organizer">
                <input type="hidden" name="id" value="{{ $organizer->id }}">
                <input type="hidden" name="notify" value="0">
                <label class="flex min-h-12 items-center gap-3"><input type="checkbox" name="notify" value="1" @checked($follow->notify)> {{ __('account.follow.notify_organizer') }}</label>
                <p class="text-sm text-ink-muted">{{ __('account.follow.organizer_hint') }}</p>
                <button class="min-h-12 border-2 border-line px-4 py-2" type="submit">{{ __('account.follow.save_notifications') }}</button>
            </form>
        @endif
    @endauth
    <p class="my-6 max-w-3xl whitespace-pre-line">{{ $organizer->description }}</p>
    @if(\App\Support\SafeUrl::href($organizer->website))<a class="underline" href="{{ \App\Support\SafeUrl::href($organizer->website) }}" rel="noopener noreferrer">Sito dell’organizzatore</a>@endif
    <nav aria-label="Archivio organizzatore" class="my-8 flex flex-wrap gap-4">
        <a class="min-h-12 border-2 border-line px-4 py-3 {{ !$past ? 'bg-brand text-on-brand' : '' }}" href="{{ route('organizers.show',$organizer) }}">In programma</a>
        <a class="min-h-12 border-2 border-line px-4 py-3 {{ $past ? 'bg-brand text-on-brand' : '' }}" href="{{ route('organizers.show',[$organizer,'past'=>1]) }}">Archivio</a>
    </nav>
    @if($occurrences->count())
        <x-event-grid :occurrences="$occurrences->getCollection()" :context="$past ? 'past' : 'upcoming'" />
    @else <p>Nessuna data {{ $past ? 'nell’archivio' : 'in programma' }} per i filtri attuali.</p> @endif
    {{ $occurrences->links() }}
</x-layouts.app>
