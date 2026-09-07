<x-layouts.app :meta="$meta" :narrow="true">
    <h1 class="text-hero">{{ $meta->heading }}</h1>
    <p class="my-6 text-ink-muted">{{ __('subscriptions.interests_help') }}</p>
    @if (session('status'))<p role="status" class="mb-5 text-accent">{{ session('status') }}</p>@endif
    @if ($errors->any())<p role="alert" class="mb-5">{{ $errors->first() }}</p>@endif
    <form method="POST" action="{{ route('account.notifications.interests.update') }}" class="space-y-8">
        @csrf @method('PATCH')
        @foreach (['categories' => $categories, 'venues' => $venues] as $group => $options)
            <fieldset>
                <legend class="mb-4 font-display text-xl font-bold">{{ __('subscriptions.'.$group) }}</legend>
                <div class="grid max-h-96 gap-2 overflow-y-auto sm:grid-cols-2">
                    @foreach ($options as $option)
                        <label class="flex items-center gap-3 border border-line p-3 has-checked:border-accent">
                            <input type="checkbox" name="{{ $group }}[]" value="{{ $option['id'] }}" @checked($option['selected']) class="size-5 accent-accent">
                            <span>{{ $option['name'] }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
        @endforeach
        <x-button type="submit">{{ __('notifications.preferences.submit') }}</x-button>
    </form>
    <a href="{{ route('account.notifications') }}" class="mt-6 inline-block underline">{{ __('subscriptions.delivery') }}</a>
</x-layouts.app>
