    <div data-home-nearby>
    <section class="px-gutter py-6 border-b-2 border-line" data-remembered-location
        data-endpoint="{{ route('location.store') }}"
        data-position="{{ isset($nearbyPosition) ? $nearbyPosition['lat'].','.$nearbyPosition['lng'] : '' }}"
        data-saved-at="{{ $nearbyPosition['saved_at'] ?? '' }}" data-authenticated="{{ auth()->check() ? '1' : '0' }}"
        data-loading="{{ __('location.loading') }}" data-unavailable="{{ __('location.unavailable') }}"
        data-failed="{{ __('location.failed') }}" data-saved="{{ __('location.saved') }}">
        <div class="section-head mb-4 flex flex-col gap-2">
            <span class="section-eyebrow font-display text-xs font-extrabold tracking-wide text-accent uppercase">{{ str_pad((string) $numero, 2, '0', STR_PAD_LEFT) }} · {{ __('events.sections.nearby_eyebrow') }}</span>
            <h2 id="sezione-vicino" class="scroll-mt-32 font-display text-[clamp(1.875rem,4vw,4rem)] leading-[0.94] font-extrabold tracking-tight uppercase">{{ __('events.sections.nearby') }}</h2>
        </div>
        <p class="max-w-prose text-sm text-ink-muted">{{ __('location.help') }}</p>
        <div class="flex flex-wrap gap-3 mt-3">
            <label class="flex items-center gap-2 text-sm">{{ __('location.radius') }}
                <select data-nearby-radius class="min-h-12 border border-line bg-canvas text-ink px-3">
                    @foreach ([5, 10] as $km)
                        <option value="{{ $km }}" @selected(($nearbyRadius ?? 5) === $km)>{{ __('location.radius_km', ['km' => $km]) }}</option>
                    @endforeach
                </select>
            </label>
            <button type="button" data-location-use class="ui-action min-h-12 border border-line px-4">{{ __('location.remember') }}</button>
            <button type="button" data-location-forget @if (!isset($nearbyPosition)) hidden @endif class="ui-action min-h-12 px-4">{{ __('location.forget') }}</button>
        </div>
        <p role="status" class="mt-2 text-sm text-ink-muted">{{ isset($nearbyPosition) ? __('location.saved') : __('location.default') }}</p>
    </section>
    @if ($nearby->isNotEmpty() && \Illuminate\Support\Facades\Route::has('map.index'))

        <section class="home-section border-b-2 border-line defer-offscreen" aria-labelledby="sezione-vicino">
            <div class="home-nearby grid gap-0.5 border-t-2 border-line bg-line [grid-template-columns:repeat(auto-fit,minmax(min(340px,100%),1fr))]">
                <div class="flex flex-col bg-canvas">
                    @foreach ($nearby as $occorrenza)
                        <a
                            href="{{ \App\Support\EventUrl::occurrence($occorrenza) }}"
                            class="group flex items-center gap-3.5 border-b-2 border-line px-[clamp(1rem,1.6vw,1.25rem)] py-3.5 transition-colors hover:bg-accent/[0.055]"
                        >
                            <span class="min-w-14 font-display text-[0.688rem] leading-none font-extrabold tracking-[0.1em] text-accent uppercase">
                                {{ $occorrenza->is_all_day ? __('events.badge.all_day') : $formatter->time($occorrenza->starts_at) }}
                            </span>
                            <span class="flex min-w-0 flex-auto flex-col gap-1">
                                <span class="font-display text-[clamp(0.938rem,1.25vw,1.188rem)] leading-[1.1] font-extrabold tracking-[-0.02em] uppercase">{{ $occorrenza->event->title }}</span>
                                <span class="truncate text-xs leading-[1.4] text-ink-subtle">
                                    {{ collect([$occorrenza->effectiveVenue()?->name, $occorrenza->effectiveVenue()?->zone ?: $occorrenza->effectiveVenue()?->municipality])->filter()->implode(' '.__('common.separator').' ') }}
                                </span>
                            </span>
                            <span class="font-display text-[0.813rem] leading-none font-extrabold whitespace-nowrap">
                                <x-price-tag :event="$occorrenza->event" />
                            </span>
                        </a>
                    @endforeach

                    <div class="mt-auto p-[18px]">
                        <a
                            href="{{ route('map.index') }}"
                            class="ui-action flex h-[46px] w-full items-center bg-accent px-4 font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] text-on-accent uppercase transition-colors hover:bg-brand-strong"
                        >
                            {{ __('ui.hero.open_map_full') }}
                        </a>
                    </div>
                </div>

                <div class="relative min-h-[clamp(25rem,44vw,35rem)] bg-canvas">
                    <x-events-map
                        :city="$city"
                        :filters="$mapFilters"
                        :payload="$mapPayload"
                        :show-legend="false"
                        class="absolute inset-0"
                        map-class="h-full w-full"
                    />
                </div>
            </div>
        </section>
    @endif
    </div>
