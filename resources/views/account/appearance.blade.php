<x-layouts.app :narrow="true" :meta="$meta">
    <section class="mx-auto max-w-2xl py-6" aria-labelledby="appearance-heading">
        <h1 id="appearance-heading" class="text-hero">Aspetto</h1>
        <p class="mt-3 text-ink-muted">Scegli la luce giusta per i tuoi eventi.</p>
        <x-appearance-picker />
        <a href="{{ route('home') }}" class="mt-6 inline-flex min-h-12 items-center text-sm underline underline-offset-4">Torna agli eventi</a>
    </section>
</x-layouts.app>
