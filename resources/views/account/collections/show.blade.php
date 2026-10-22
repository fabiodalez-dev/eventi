<x-layouts.app :meta="$meta" :narrow="true">
    <a href="{{ route('account.collections.index') }}" class="inline-flex min-h-12 items-center underline">{{ __('collections.back') }}</a>
    <h1 class="text-hero">{{ $collection->name }}</h1>
    @if (filled($collection->description))<p class="my-3 text-ink-muted">{{ $collection->description }}</p>@endif
    @if ($errors->any())<p role="alert" class="my-4 text-sm font-semibold">{{ $errors->first() }}</p>@endif

    <form method="POST" action="{{ route('account.collections.update', $collection) }}" class="my-6 grid gap-4 border-2 border-line bg-canvas p-5">
        @csrf @method('PATCH')
        <x-field name="name" :label="__('collections.fields.name')" :value="$collection->name" :required="true" maxlength="120" />
        <x-field name="description" :label="__('collections.fields.description')" :value="$collection->description" maxlength="500" />
        <label class="flex items-start gap-2 text-sm"><input type="hidden" name="is_public" value="0"><input type="checkbox" name="is_public" value="1" @checked($collection->is_public) class="mt-1"> <span>{{ __('collections.public') }}<br><span class="text-ink-muted">{{ __('collections.public_hint') }}</span></span></label>
        @if ($collection->is_public)
            <p class="text-sm"><strong>{{ __('collections.link') }}</strong><br><a href="{{ $collection->publicUrl() }}" class="break-all underline" data-collection-link>{{ $collection->publicUrl() }}</a></p>
        @endif
        <div class="flex flex-wrap gap-3">
            <x-button type="submit" class="min-h-12">{{ __('collections.save') }}</x-button>
        </div>
    </form>

    <form method="POST" action="{{ route('account.collections.destroy', $collection) }}" class="mb-8" data-confirm="{{ __('collections.delete_confirm') }}">
        @csrf @method('DELETE')
        <x-button type="submit" variant="ghost">{{ __('collections.delete') }}</x-button>
    </form>

    @forelse ($occurrences as $occurrence)
        <div class="border-t-2 border-line py-4">
            <x-event-card :occurrence="$occurrence" />
            <form method="POST" action="{{ route('account.collections.items.destroy', [$collection, $occurrence->getKey()]) }}" class="mt-2">
                @csrf @method('DELETE')
                <x-button type="submit" variant="ghost">{{ __('collections.remove') }}</x-button>
            </form>
        </div>
    @empty
        <p class="text-sm text-ink-muted">{{ __('collections.empty') }}</p>
    @endforelse
</x-layouts.app>
