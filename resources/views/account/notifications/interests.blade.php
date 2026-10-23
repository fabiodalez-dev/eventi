<x-layouts.app :meta="$meta" :narrow="true">
    <h1 class="text-hero">{{ $meta->heading }}</h1>
    <p class="my-6 text-ink-muted">{{ __('subscriptions.interests_help') }}</p>
    @if ($errors->any())<p role="alert" class="mb-5">{{ $errors->first() }}</p>@endif
    <form method="POST" action="{{ route('account.notifications.interests.update') }}" class="space-y-8">
        @csrf @method('PATCH')
        @foreach (['categories' => $categories, 'venues' => $venues] as $group => $options)
            <fieldset>
                <legend class="mb-4 font-display text-xl font-bold">{{ __('subscriptions.'.$group) }}</legend>
                <div class="grid max-h-96 gap-2 overflow-y-auto sm:grid-cols-2">
                    @foreach ($options as $option)
                        <label class="flex items-center gap-3 border border-line p-3 has-checked:border-accent">
                            <input type="checkbox" name="{{ $group }}[]" value="{{ $option['id'] }}" @checked($option['selected']) class="size-5 shrink-0 accent-accent">
                            <span>{{ $option['name'] }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
        @endforeach
        <x-button type="submit">{{ __('notifications.preferences.submit') }}</x-button>
    </form>
    <section class="mt-10 border-t-2 border-line pt-6" aria-labelledby="ricerche-salvate">
        <h2 id="ricerche-salvate" class="font-display text-xl font-bold">{{ __('search.saved.title') }}</h2>
        <p class="my-3 text-sm text-ink-muted">{{ __('search.saved.help') }}</p>
        @forelse ($searches as $search)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line py-3">
                <div><a href="{{ $search->url ?? route('events.index', $search->queryString()) }}" class="font-semibold underline">{{ $search->name }}</a>
                    <p class="text-xs text-ink-muted">{{ $search->last_checked_at ? __('search.saved.checked', ['date' => $search->last_checked_at->timezone(auth()->user()->timezone ?: config('app.timezone'))->format('d/m/Y H:i')]) : __('search.saved.never') }}</p></div>
                <form method="POST" action="{{ route('account.searches.destroy', $search) }}">@csrf @method('DELETE')<x-button type="submit" variant="ghost">{{ __('search.saved.remove') }}</x-button></form>
            </div>
        @empty
            <p class="text-sm text-ink-muted">{{ __('search.saved.none') }}</p>
        @endforelse
    </section>
    <a href="{{ route('account.notifications') }}" class="mt-6 inline-block underline">{{ __('subscriptions.delivery') }}</a>
</x-layouts.app>
