<x-filament-panels::page>
    <form wire:submit="save" class="space-y-4">
        {{ $this->form }}
        <x-filament::button type="submit">{{ __('seo.search_console.save') }}</x-filament::button>
    </form>
    <ol class="list-decimal pl-5 space-y-2">
        <li>{{ __('seo.search_console.step1') }} <code>{{ url('/') }}</code></li>
        <li>{{ __('seo.search_console.step2') }}</li>
        <li>{{ __('seo.search_console.step3') }}</li>
        <li>{{ __('seo.search_console.step4') }} <code>{{ route('sitemap.index') }}</code></li>
    </ol>
    <p>{{ __('seo.audit.help') }}</p>
    <div class="flex flex-wrap gap-4">
        <a class="underline" href="{{ route('sitemap.index') }}" target="_blank" rel="noopener">Sitemap XML</a>
        <a class="underline" href="https://search.google.com/search-console" target="_blank" rel="noopener">Google Search Console</a>
        <a class="underline" href="https://search.google.com/test/rich-results" target="_blank" rel="noopener">Rich Results Test</a>
        <a class="underline" href="https://validator.schema.org/" target="_blank" rel="noopener">Schema.org Validator</a>
        <a class="underline" href="https://pagespeed.web.dev/" target="_blank" rel="noopener">PageSpeed Insights</a>
    </div>
    @foreach (app(\App\Services\Seo\SeoAudit::class)->report() as $row)
        <section class="py-4 border-b border-gray-300">
            <h2 class="font-bold"><a class="underline" href="{{ $row['url'] }}" target="_blank" rel="noopener">{{ $row['title'] }}</a></h2>
            @forelse ($row['issues'] as $issue)
                <p>{{ $issue }}</p>
            @empty
                <p>{{ __('seo.audit.complete') }}</p>
            @endforelse
        </section>
    @endforeach
</x-filament-panels::page>
