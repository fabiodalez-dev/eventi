<x-layouts.app :narrow="true" :meta="$meta">
    <section class="mx-auto flex w-full max-w-lg flex-col gap-6 py-4" aria-labelledby="verification-title">
        <div class="flex items-center gap-3 text-sm font-semibold text-brand">
            <svg class="size-6 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 6 9 7 9-7"/></svg>
            <span>{{ __('account.verify.step') }}</span>
        </div>
        <header class="flex flex-col gap-4">
            <h1 id="verification-title" class="font-display text-4xl leading-tight font-extrabold tracking-tight text-ink">{{ __('account.verify.title') }}</h1>
            <p class="text-base leading-relaxed text-ink-muted">{{ __('account.verify.instructions') }}</p>
            <p class="break-words text-lg font-semibold text-ink">{{ $email }}</p>
        </header>
        <p class="text-sm leading-relaxed text-ink-muted">{{ __('account.verify.benefits') }}</p>
        <div class="flex flex-col gap-4 border-t border-line pt-6">
            <p class="text-sm leading-relaxed text-ink-muted">{{ __('account.verify.help') }}</p>
            <form method="POST" action="{{ route('account.verification.send') }}">
                @csrf
                <button type="submit" class="ui-action min-h-12 w-full bg-brand px-5 py-3 text-sm font-semibold text-on-brand transition hover:bg-brand-strong focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand sm:w-auto">{{ __('account.verify.resend') }}</button>
            </form>
            <a class="inline-flex min-h-12 items-center text-sm font-semibold text-ink underline underline-offset-4" href="{{ route('home') }}">{{ __('account.verify.explore') }}</a>
        </div>
    </section>
</x-layouts.app>
