<x-layouts.app :narrow="true" :meta="$meta">
    <section class="flex w-full flex-col gap-6" aria-labelledby="verification-app-title">
        <header class="flex flex-col gap-4">
            <h1 id="verification-app-title" class="font-display text-4xl leading-tight font-extrabold tracking-tight text-ink">{{ __('account.verify.app_title') }}</h1>
            <p class="text-base leading-relaxed text-ink-muted">{{ __('account.verify.app_lead') }}</p>
        </header>
        <div class="flex flex-col gap-4 border-t border-line pt-6">
            <a class="ui-action inline-flex min-h-12 w-full items-center justify-center bg-brand px-5 py-3 text-sm font-semibold text-on-brand transition hover:bg-brand-strong focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand sm:w-auto" href="{{ $intent }}" rel="noreferrer">{{ __('account.verify.app_open') }}</a>
            <a class="inline-flex min-h-12 items-center text-sm font-semibold text-ink underline underline-offset-4" href="{{ $fallback }}">{{ __('account.verify.app_continue') }}</a>
        </div>
    </section>
</x-layouts.app>
