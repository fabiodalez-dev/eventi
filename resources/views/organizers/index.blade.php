<x-layouts.app :meta="$meta">
    <h1 class="text-hero">Organizzatori</h1>
    <p class="mt-4 text-ink-muted">Associazioni, collettivi e promotori: tutti i loro eventi, anche in locali diversi.</p>
    <form method="GET" class="my-6 flex flex-wrap gap-3">
        <label class="flex-1">Cerca un organizzatore<input class="mt-2 block min-h-12 w-full border-2 border-line bg-canvas p-3" name="q" value="{{ $term }}" maxlength="120"></label>
        <x-button type="submit">Cerca</x-button>
    </form>
    <div class="divide-y divide-line">
        @forelse($organizers as $organizer)
            <a class="block py-5 text-xl font-bold underline" href="{{ route('organizers.show',$organizer) }}">{{ $organizer->name }}</a>
        @empty <p>Nessun organizzatore trovato.</p> @endforelse
    </div>
    {{ $organizers->links() }}
</x-layouts.app>
