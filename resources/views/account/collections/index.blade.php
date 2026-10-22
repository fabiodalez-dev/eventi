<x-layouts.app :meta="$meta" :narrow="true">
    <h1 class="text-hero">{{ $meta->heading }}</h1>
    <p class="my-6 text-ink-muted">{{ __('collections.lead') }}</p>
    @if ($errors->any())<p role="alert" class="mb-5 text-sm font-semibold">{{ $errors->first() }}</p>@endif

    <form method="POST" action="{{ route('account.collections.store') }}" class="mb-8 grid gap-4 border-2 border-line bg-canvas p-5">
        @csrf
        <h2 class="font-display text-xl font-bold">{{ __('collections.new') }}</h2>
        <x-field name="name" :label="__('collections.fields.name')" :required="true" maxlength="120" />
        <x-field name="description" :label="__('collections.fields.description')" maxlength="500" />
        <label class="flex items-center gap-2 text-sm"><input type="hidden" name="is_public" value="0"><input type="checkbox" name="is_public" value="1" @checked(old('is_public'))> {{ __('collections.public') }}</label>
        <x-button type="submit" class="min-h-12 justify-self-start">{{ __('collections.create') }}</x-button>
    </form>

    @forelse ($collections as $collection)
        <div class="flex flex-wrap items-center justify-between gap-3 border-t-2 border-line py-4">
            <div>
                <a href="{{ route('account.collections.show', $collection) }}" class="font-display text-lg font-extrabold uppercase underline">{{ $collection->name }}</a>
                <p class="text-sm text-ink-muted">{{ trans_choice('collections.items', $collection->items_count, ['count' => $collection->items_count]) }} · {{ $collection->is_public ? __('collections.public') : __('collections.private') }}</p>
            </div>
            <x-button :href="route('account.collections.show', $collection)" variant="secondary">{{ __('collections.open') }}</x-button>
        </div>
    @empty
        <p class="text-sm text-ink-muted">{{ __('collections.none') }}</p>
    @endforelse
</x-layouts.app>
