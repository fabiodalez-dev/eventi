<x-layouts.app :meta="$meta">
    <section class="mx-auto max-w-5xl py-8 sm:py-14">
        <p class="text-eyebrow text-accent">{{ $city->name }} / {{ __('tonight.eyebrow') }}</p>
        <h1 class="mt-4 max-w-3xl font-display text-4xl font-extrabold leading-tight sm:text-6xl">{{ $question === 'results' ? __('tonight.results') : __('tonight.title') }}</h1>
        <p class="mt-5 max-w-prose text-ink-muted">{{ __('tonight.lead') }}</p>
        @if($errors->any())<p role="alert" class="my-4">{{ $errors->first() }}</p>@endif
        @if($question !== 'results')
            @include('tonight.question')
        @else
            <p class="mb-6 max-w-prose text-sm text-ink-muted">{{ __('tonight.order') }}</p>
            @forelse($dates as $date)
                <article class="mb-10 border-y-2 border-line py-6">
                    <p class="text-eyebrow text-accent">0{{ $loop->iteration }} / {{ __('tonight.why') }}</p>
                    <ul class="my-4 flex list-none flex-wrap gap-x-6 gap-y-2 text-sm text-ink-muted">
                        @foreach($discovery->reasons($date, $input) as $reason)<li>{{ $reason }}</li>@endforeach
                    </ul>
                    <x-event-card :occurrence="$date" :href="\App\Support\EventUrl::occurrence($date)" />
                    <details class="mt-4">
                        <summary class="min-h-12 cursor-pointer py-3 font-bold">{{ __('seo.before_going') }}</summary>
                        <dl class="grid gap-4 py-4 sm:grid-cols-2">
                            @foreach($discovery->practical($date) as $fact)
                                <div><dt class="text-sm text-ink-muted">{{ $fact['label'] }}</dt><dd class="mt-1 whitespace-pre-line">{{ $fact['value'] }}</dd></div>
                            @endforeach
                        </dl>
                    </details>
                </article>
            @empty
                <h2 class="text-2xl font-bold">{{ __('tonight.empty') }}</h2>
                <p class="my-4 max-w-prose text-ink-muted">{{ __('tonight.empty_help') }}</p>
            @endforelse
            <nav class="flex flex-wrap gap-4" aria-label="{{ __('tonight.edit') }}">
                <a class="min-h-14 bg-brand px-6 py-4 font-bold text-on-brand" href="{{ route('tonight.wizard', [...\Illuminate\Support\Arr::except($input, ['step']), 'question'=>'municipality']) }}">{{ __('tonight.edit') }}</a>
                <a class="inline-flex min-h-14 items-center border-2 border-line px-6 py-4" href="{{ route('events.index') }}">{{ __('tonight.all') }}</a>
            </nav>
        @endif
        @auth<a class="mt-6 inline-flex min-h-12 items-center underline" href="{{ route('account.content-preferences') }}">{{ __('tonight.preferences') }}</a>@endauth
    </section>
</x-layouts.app>
