@php
    $tools = [
        'sitemap' => route('sitemap.index'),
        'search_console' => 'https://search.google.com/search-console',
        'rich_results' => 'https://search.google.com/test/rich-results',
        'schema' => 'https://validator.schema.org/',
        'pagespeed' => 'https://pagespeed.web.dev/',
    ];
@endphp
<x-filament-panels::page>
    {{-- Il modulo ha già la sua card con il pulsante «Salva»; Invio lo salva. --}}
    <form wire:submit="save">
        {{ $this->form }}
    </form>

    <x-filament::section :heading="__('admin.seo_page.steps_heading')">
        <ol class="flex list-decimal flex-col gap-2 ps-5 text-sm text-gray-950 dark:text-white">
            <li class="[overflow-wrap:anywhere]">{{ __('seo.search_console.step1') }} <code class="rounded bg-gray-100 px-1 py-0.5 text-xs dark:bg-white/10">{{ url('/') }}</code></li>
            <li>{{ __('seo.search_console.step2') }}</li>
            <li>{{ __('seo.search_console.step3') }}</li>
            <li class="[overflow-wrap:anywhere]">{{ __('seo.search_console.step4') }} <code class="rounded bg-gray-100 px-1 py-0.5 text-xs dark:bg-white/10">{{ route('sitemap.index') }}</code></li>
        </ol>
    </x-filament::section>

    <x-filament::section :heading="__('admin.seo_page.tools_heading')" :description="__('admin.seo_page.tools_description')">
        <div class="flex flex-wrap gap-3">
            @foreach ($tools as $key => $url)
                <x-filament::button tag="a" :href="$url" target="_blank" rel="noopener" color="gray" outlined size="sm" icon="heroicon-o-arrow-top-right-on-square" icon-position="after">
                    {{ __('admin.seo_page.tools.'.$key) }}
                </x-filament::button>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('admin.seo_page.audit_heading')" :description="__('seo.audit.help')" data-seo-audit>
        <div class="flex min-w-0 flex-col gap-4">
            <x-filament::tabs :label="__('admin.seo_page.audit_heading')" class="max-w-full flex-wrap">
                @foreach (['issues', 'all'] as $tab)
                    <x-filament::tabs.item :active="$filter === $tab" :badge="$counts[$tab]" wire:click="$set('filter', '{{ $tab }}')">
                        {{ __('admin.seo_page.filters.'.$tab) }}
                    </x-filament::tabs.item>
                @endforeach
            </x-filament::tabs>

            @if ($rows->isEmpty())
                <x-filament::empty-state icon="heroicon-o-check-badge" :heading="__('admin.seo_page.empty')" :description="__('seo.audit.complete')" />
            @else
                <ul class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($rows as $row)
                        <li class="flex min-w-0 flex-col gap-2 py-4 first:pt-0 last:pb-0" wire:key="seo-{{ md5($row['url']) }}" data-seo-row>
                            <a href="{{ $row['url'] }}" target="_blank" rel="noopener" class="text-sm font-semibold text-gray-950 hover:underline [overflow-wrap:anywhere] dark:text-white">{{ $row['title'] }}</a>
                            @if ($row['issues'] === [])
                                <p class="flex items-start gap-1.5 text-sm text-success-700 dark:text-success-400">
                                    <x-filament::icon icon="heroicon-o-check-circle" class="mt-0.5 size-4 shrink-0" />
                                    <span>{{ __('seo.audit.complete') }}</span>
                                </p>
                            @else
                                <ul class="flex flex-col gap-1">
                                    @foreach ($row['issues'] as $issue)
                                        <li class="flex items-start gap-1.5 text-sm text-gray-700 dark:text-gray-300">
                                            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="mt-0.5 size-4 shrink-0 text-warning-600 dark:text-warning-400" />
                                            <span class="min-w-0 [overflow-wrap:anywhere]">{{ $issue }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </li>
                    @endforeach
                </ul>

                <x-filament::pagination :paginator="$rows" />
            @endif
        </div>
    </x-filament::section>
</x-filament-panels::page>
