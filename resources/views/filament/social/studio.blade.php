{{--
    Lo studio social in tre card, nell'ordine in cui si lavora: scegliere le
    serate, decidere formato e stile, scaricare le grafiche pronte. Nessun
    colore scritto a mano: classi del tema con la variante scura.
--}}
@php
    $isAdmin = $this->socialVenue() === null;
    $timezone = $this->socialCity()->timezone;
    $catalog = $this->catalog();
    $total = $this->catalogTotal();
    $dates = $this->dates();
    $selectedIds = array_map('intval', $selected);
    $chosen = $dates->whereIn('id', $selectedIds)->values();
    $when = fn (\App\Models\EventOccurrence $occurrence): string => ucfirst($occurrence->starts_at->timezone($timezone)->locale('it')->translatedFormat('D j M'))
        .' · '.($occurrence->is_all_day ? __('social.all_day') : $occurrence->starts_at->timezone($timezone)->format('H:i'));
    $statusColor = fn (\App\Enums\OccurrenceStatus $status): string => match ($status) {
        \App\Enums\OccurrenceStatus::Scheduled => 'success',
        \App\Enums\OccurrenceStatus::Cancelled => 'danger',
        default => 'warning',
    };
@endphp
<x-filament-panels::page>
    <div class="flex flex-col gap-6" data-social-studio>
        @if ($errors->isNotEmpty())
            <x-filament::callout color="danger" icon="heroicon-o-exclamation-triangle" role="alert" data-social-errors>
                <x-slot name="description">
                    <ul class="flex flex-col gap-1">
                        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </x-slot>
            </x-filament::callout>
        @endif

        <x-filament::section :heading="__('social.studio.choose')" :description="__('social.studio.choose_hint')" icon="heroicon-o-calendar-days" data-social-choose>
            <div class="flex flex-col gap-5">
                @if ($event !== null && ($onlyEvent = \App\Models\Event::find($event)))
                    <x-filament::callout color="info" icon="heroicon-o-funnel" :description="__('social.studio.only_event', ['title' => $onlyEvent->title])">
                        {{-- Nel piede e non fra i controlli: a 390 px il pulsante
                             accanto al testo lo schiacciava a una lettera per riga. --}}
                        <x-slot name="footer">
                            <x-filament::button color="gray" size="sm" wire:click="clearEvent">{{ __('social.clear_event') }}</x-filament::button>
                        </x-slot>
                    </x-filament::callout>
                @endif

                @if ($isAdmin)
                    <div class="flex flex-col gap-1.5">
                        <label for="social-city" class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('social.city') }}</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="social-city" wire:model.live="cityId">
                                @foreach (\App\Models\City::orderBy('name')->get() as $city)<option value="{{ $city->id }}">{{ $city->name }}</option>@endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                @endif

                <div class="flex flex-col gap-2">
                    <span class="text-sm font-semibold text-gray-950 dark:text-white" id="social-period-label">{{ __('social.studio.period_label') }}</span>
                    <div class="flex flex-wrap gap-2" role="group" aria-labelledby="social-period-label" data-social-periods>
                        @foreach ($this::PERIODS as $value)
                            <x-filament::button size="sm" :color="$period === $value ? 'primary' : 'gray'" :outlined="$period !== $value"
                                wire:click="setPeriod('{{ $value }}')" :aria-pressed="$period === $value ? 'true' : 'false'">{{ __('social.studio.periods.'.$value) }}</x-filament::button>
                        @endforeach
                    </div>
                </div>

                @if ($period === 'custom')
                    <div class="flex flex-wrap gap-3" data-social-custom-period>
                        <div class="flex min-w-40 flex-1 flex-col gap-1.5">
                            <label for="social-from" class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('social.studio.from') }}</label>
                            <x-filament::input.wrapper><x-filament::input id="social-from" type="date" wire:model.live="from" /></x-filament::input.wrapper>
                        </div>
                        <div class="flex min-w-40 flex-1 flex-col gap-1.5">
                            <label for="social-until" class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('social.studio.until') }}</label>
                            <x-filament::input.wrapper><x-filament::input id="social-until" type="date" wire:model.live="until" /></x-filament::input.wrapper>
                        </div>
                    </div>
                @endif

                <div class="flex flex-col gap-1.5">
                    <label for="social-search" class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('social.studio.search') }}</label>
                    <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                        <x-filament::input id="social-search" type="search" maxlength="100" wire:model.live.debounce.400ms="search" :placeholder="__('social.studio.search_placeholder')" />
                    </x-filament::input.wrapper>
                </div>

                <div class="flex w-full max-w-sm flex-col gap-2" data-social-calendar>
                    <div class="flex items-center justify-between gap-2">
                        <x-filament::icon-button icon="heroicon-o-chevron-left" color="gray" wire:click="shiftMonth(-1)" :label="__('social.studio.previous_month')" />
                        <h3 class="text-sm font-bold text-gray-950 dark:text-white" aria-live="polite">{{ $this->monthLabel() }}</h3>
                        <x-filament::icon-button icon="heroicon-o-chevron-right" color="gray" wire:click="shiftMonth(1)" :label="__('social.studio.next_month')" />
                    </div>
                    <div class="grid grid-cols-7 gap-1 text-center" role="group" aria-label="{{ __('social.studio.calendar') }}">
                        @foreach (__('social.studio.weekdays') as $weekday)
                            <span class="py-1 text-xs font-semibold text-gray-500 dark:text-gray-400" aria-hidden="true">{{ $weekday }}</span>
                        @endforeach
                        @foreach ($this->calendarDays() as $day)
                            @php $dayLabel = trans_choice('social.studio.day_count', $day['count'], ['day' => ucfirst(\Carbon\CarbonImmutable::parse($day['date'])->locale('it')->translatedFormat('l j F')), 'count' => $day['count']]); @endphp
                            @if ($day['count'] > 0)
                                <button type="button" wire:click="pickDay('{{ $day['date'] }}')" wire:key="day-{{ $day['date'] }}" aria-label="{{ $dayLabel }}" aria-pressed="{{ $day['selected'] ? 'true' : 'false' }}" data-calendar-day="{{ $day['date'] }}"
                                    @class([
                                        'flex min-h-10 flex-col items-center justify-center rounded-lg text-sm font-bold tabular-nums transition',
                                        'bg-primary-600 text-white dark:bg-primary-500' => $day['selected'],
                                        'bg-primary-50 text-primary-700 hover:bg-primary-100 dark:bg-primary-500/15 dark:text-primary-300 dark:hover:bg-primary-500/25' => ! $day['selected'],
                                        'opacity-60' => ! $day['inMonth'] && ! $day['selected'],
                                        'ring-2 ring-gray-950/70 dark:ring-white/70' => $day['today'] && ! $day['selected'],
                                    ])>
                                    <span>{{ $day['day'] }}</span>
                                    <span class="text-[0.625rem] font-semibold leading-none" aria-hidden="true">{{ $day['count'] }}</span>
                                </button>
                            @else
                                <span wire:key="day-{{ $day['date'] }}"
                                    @class([
                                        'flex min-h-10 items-center justify-center rounded-lg text-sm tabular-nums text-gray-500 dark:text-gray-400',
                                        'opacity-40' => ! $day['inMonth'],
                                        'ring-1 ring-gray-300 dark:ring-white/20' => $day['today'],
                                    ])>{{ $day['day'] }}</span>
                            @endif
                        @endforeach
                    </div>
                </div>

                <p class="text-sm font-semibold text-gray-950 dark:text-white" aria-live="polite" data-social-total>{{ trans_choice('social.studio.results', $total, ['count' => $total]) }}</p>

                @if ($catalog->isEmpty())
                    <x-filament::empty-state :heading="__('social.studio.empty_title')" :description="__('social.studio.empty_body')" icon="heroicon-o-calendar" :contained="false" />
                @else
                    <ul class="flex flex-col gap-3" data-social-catalog>
                        @foreach ($catalog as $occurrence)
                            @php
                                $selectable = $occurrence->status === \App\Enums\OccurrenceStatus::Scheduled;
                                $isPicked = $this->isSelected($occurrence, $selected);
                                $whenLabel = $when($occurrence);
                            @endphp
                            <li wire:key="catalog-{{ $occurrence->id }}">
                                <button type="button" wire:click="pick({{ $occurrence->id }})" @disabled(! $selectable)
                                    aria-pressed="{{ $isPicked ? 'true' : 'false' }}"
                                    aria-label="{{ __($isPicked ? 'social.studio.remove_label' : 'social.studio.pick_label', ['title' => $occurrence->event->title, 'when' => $whenLabel]) }}"
                                    data-social-card="{{ $occurrence->id }}"
                                    @class([
                                        'flex w-full items-center gap-3 rounded-xl border p-3 text-start transition sm:gap-4',
                                        'border-primary-600 bg-primary-50 dark:border-primary-400 dark:bg-primary-500/10' => $isPicked,
                                        'border-gray-200 bg-white hover:border-gray-300 hover:bg-gray-50 dark:border-white/10 dark:bg-white/5 dark:hover:border-white/20 dark:hover:bg-white/10' => ! $isPicked,
                                        'cursor-not-allowed opacity-70' => ! $selectable,
                                    ])>
                                    <img src="{{ \App\Filament\Support\PosterColumn::url($occurrence->event) }}" alt="" width="56" height="75" loading="lazy" decoding="async"
                                        class="h-[75px] w-14 shrink-0 rounded-md border border-gray-200 bg-gray-100 object-cover dark:border-white/10 dark:bg-white/5" data-social-poster>
                                    <span class="flex min-w-0 flex-1 flex-col gap-1">
                                        <span class="font-semibold leading-snug text-gray-950 [overflow-wrap:anywhere] dark:text-white">{{ $occurrence->event->title }}</span>
                                        <span class="text-sm text-gray-600 dark:text-gray-300">{{ $whenLabel }}@if ($isAdmin && $occurrence->effectiveVenue()) · {{ $occurrence->effectiveVenue()->name }}@endif</span>
                                        <span class="mt-1 flex flex-wrap gap-1.5">
                                            <x-filament::badge size="sm" :color="$statusColor($occurrence->status)">{{ $occurrence->status->label() }}</x-filament::badge>
                                            @if ($occurrence->event->is_demo)
                                                <x-filament::badge size="sm" color="gray" icon="heroicon-m-beaker" data-social-demo>{{ __('social.studio.demo') }}</x-filament::badge>
                                            @endif
                                            @unless ($selectable)
                                                <x-filament::badge size="sm" color="gray">{{ __('social.studio.not_available') }}</x-filament::badge>
                                            @endunless
                                        </span>
                                    </span>
                                    @if ($selectable)
                                        <span @class([
                                            'flex size-10 shrink-0 items-center justify-center rounded-full border',
                                            'border-primary-600 bg-primary-600 text-white dark:border-primary-400 dark:bg-primary-500' => $isPicked,
                                            'border-gray-300 text-gray-500 dark:border-white/20 dark:text-gray-400' => ! $isPicked,
                                        ]) aria-hidden="true">
                                            <x-filament::icon :icon="$isPicked ? 'heroicon-m-check' : 'heroicon-m-plus'" class="size-5" />
                                        </span>
                                    @endif
                                </button>
                            </li>
                        @endforeach
                    </ul>
                    @if ($total > $catalog->count())
                        <div><x-filament::button color="gray" wire:click="showMore" icon="heroicon-o-chevron-down">{{ __('social.studio.more') }}</x-filament::button></div>
                    @endif
                    @if ($this->includesDemo() && $catalog->contains(fn ($occurrence) => $occurrence->event->is_demo))
                        <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('social.studio.demo_hint') }}</p>
                    @endif
                @endif
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('social.studio.style')" :description="__('social.studio.style_hint')" icon="heroicon-o-paint-brush" data-social-style>
            <div class="flex flex-col gap-5">
                <dl class="flex flex-wrap gap-x-8 gap-y-3" data-social-day>
                    <div><dt class="text-xs font-semibold text-gray-500 dark:text-gray-400">{{ __('social.studio.day') }}</dt><dd class="font-semibold text-gray-950 dark:text-white">{{ $this->dateLabel() }}</dd></div>
                    <div><dt class="text-xs font-semibold text-gray-500 dark:text-gray-400">{{ __('social.studio.selection') }}</dt><dd class="font-semibold text-gray-950 dark:text-white">{{ trans_choice('social.studio.selected_count', $chosen->count(), ['count' => $chosen->count()]) }}</dd></div>
                </dl>

                @if ($dates->isNotEmpty())
                    <ul class="-mx-1 flex snap-x gap-3 overflow-x-auto px-1 pb-2" aria-label="{{ __('social.studio.selection') }}" data-social-previews>
                        @foreach ($dates as $occurrence)
                            <li wire:key="preview-{{ $occurrence->id }}" class="w-40 shrink-0 snap-start">
                                <label class="flex cursor-pointer flex-col gap-2">
                                    <img src="{{ \App\Services\Social\SocialStudio::previewUrl($occurrence) }}" alt="{{ __('social.preview') }}: {{ $occurrence->event->title }}" loading="lazy" width="160" height="200"
                                        class="aspect-[4/5] w-40 rounded-lg border border-gray-200 bg-gray-100 object-contain dark:border-white/10 dark:bg-white/5">
                                    <span class="flex items-start gap-2 text-sm text-gray-950 dark:text-white">
                                        <x-filament::input.checkbox wire:model.live="selected" value="{{ $occurrence->id }}" class="mt-0.5 shrink-0" />
                                        <span class="min-w-0 [overflow-wrap:anywhere]">{{ $occurrence->event->title }}</span>
                                    </span>
                                </label>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('social.studio.nothing_selected') }}</p>
                @endif

                <div class="flex flex-col gap-1.5">
                    <label for="social-format" class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('social.format') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="social-format" wire:model="format">
                            @foreach (['portrait', 'square', 'story'] as $value)<option value="{{ $value }}">{{ __('social.'.$value) }}</option>@endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="social-fit" class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('social.image_fit') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="social-fit" wire:model="imageFit">
                            <option value="contain">{{ __('social.contain') }}</option>
                            <option value="cover">{{ __('social.cover') }}</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
                <fieldset class="flex flex-col gap-3">
                    <legend class="mb-2 text-sm font-semibold text-gray-950 dark:text-white">{{ __('social.options') }}</legend>
                    <label class="flex min-h-6 items-center gap-3 text-sm text-gray-950 dark:text-white"><x-filament::input.checkbox wire:model="monochrome" /> {{ __('social.monochrome') }}</label>
                    <label class="flex min-h-6 items-center gap-3 text-sm text-gray-950 dark:text-white"><x-filament::input.checkbox wire:model="showAddress" /> {{ __('social.address') }}</label>
                    <label class="flex min-h-6 items-center gap-3 text-sm text-gray-950 dark:text-white"><x-filament::input.checkbox wire:model="showPrice" /> {{ __('social.price') }}</label>
                </fieldset>
                <div class="flex flex-col gap-1.5">
                    <label for="social-title" class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('social.short_title') }}</label>
                    <x-filament::input.wrapper :valid="! $errors->has('graphicTitle')">
                        <x-filament::input id="social-title" wire:model="graphicTitle" maxlength="300" />
                    </x-filament::input.wrapper>
                </div>

                <div class="flex flex-wrap items-center gap-3 border-t border-gray-200 pt-5 dark:border-white/10">
                    <x-filament::button wire:click="generate" wire:loading.attr="disabled" wire:target="generate" icon="heroicon-o-sparkles" :disabled="$chosen->isEmpty()" data-social-generate>{{ __('social.generate') }}</x-filament::button>
                    @if ($dates->count() > 1)
                        <x-filament::button color="gray" wire:click="selectAll">{{ __('social.select_all') }}</x-filament::button>
                    @endif
                    <span wire:loading wire:target="generate" class="text-sm text-gray-600 dark:text-gray-400" role="status">{{ __('social.generating') }}</span>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('social.studio.ready')" :description="__('social.studio.ready_hint')" icon="heroicon-o-arrow-down-tray" data-social-ready>
            <div class="flex flex-col gap-6">
                @if ($batch = $this->batch())
                    <div class="flex flex-col gap-4" data-social-batch>
                        <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('social.snapshot') }}</p>
                        <ul class="-mx-1 flex snap-x gap-3 overflow-x-auto px-1 pb-2">
                            @foreach ($batch->items as $i => $item)
                                <li class="w-40 shrink-0 snap-start" wire:key="batch-{{ $batch->id }}-{{ $i }}">
                                    <a href="{{ \App\Services\Social\SocialStudio::imageUrl($batch, $i) }}" target="_blank" rel="noopener" class="flex flex-col gap-2 text-sm text-gray-950 dark:text-white" aria-label="{{ __('social.studio.open_image', ['number' => $i + 1]) }}: {{ $item['title'] }}">
                                        <img src="{{ \App\Services\Social\SocialStudio::imageUrl($batch, $i) }}" alt="" loading="lazy" class="w-40 rounded-lg border border-gray-200 dark:border-white/10">
                                        <span class="[overflow-wrap:anywhere]">{{ $i + 1 }}. {{ $item['title'] }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                        <div><x-filament::button tag="a" :href="route('social.zip', $batch)" icon="heroicon-o-arrow-down-tray">{{ __('social.download') }}</x-filament::button></div>
                        @if ($isAdmin)
                            @foreach (app(\App\Services\Social\SocialPublisher::class)->captions($batch) as $caption)
                                <div class="flex flex-col gap-2">
                                    <strong class="text-sm text-gray-950 dark:text-white">{{ __('social.caption_preview') }}</strong>
                                    <p class="whitespace-pre-wrap rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-950 [overflow-wrap:anywhere] dark:border-white/10 dark:bg-white/5 dark:text-white">{{ $caption }}</p>
                                </div>
                            @endforeach
                            <div class="flex flex-col gap-3 border-t border-gray-200 pt-5 dark:border-white/10" data-social-schedule>
                                <h3 class="text-base font-bold text-gray-950 dark:text-white">{{ __('social.studio.publication') }}</h3>
                                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('social.schedule.lead', ['timezone' => $timezone]) }}</p>
                                <div class="flex flex-col gap-1.5">
                                    <label for="social-scheduled-at" class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('social.schedule.when') }}</label>
                                    <x-filament::input.wrapper :valid="! $errors->has('scheduledAt')">
                                        <x-filament::input id="social-scheduled-at" type="datetime-local" wire:model="scheduledAt" />
                                    </x-filament::input.wrapper>
                                </div>
                                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('social.schedule.note') }}</p>
                                <div class="flex flex-wrap gap-3">
                                    <x-filament::button wire:click="publish" wire:confirm="{{ __('social.confirm') }}" wire:loading.attr="disabled">{{ __('social.publish') }}</x-filament::button>
                                    <x-filament::button color="gray" wire:click="schedulePublication" wire:loading.attr="disabled">{{ __('social.schedule.action') }}</x-filament::button>
                                </div>
                            </div>
                        @else
                            <div class="flex flex-col gap-2">
                                <strong class="text-sm text-gray-950 dark:text-white">{{ __('social.caption_label') }}</strong>
                                <p class="whitespace-pre-wrap rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-950 [overflow-wrap:anywhere] dark:border-white/10 dark:bg-white/5 dark:text-white">{{ $batch->caption }}</p>
                            </div>
                        @endif
                    </div>
                @else
                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('social.studio.ready_empty') }}</p>
                @endif

                @if (($recent = $this->recentBatches())->isNotEmpty())
                    <div class="flex flex-col gap-2" data-social-recent>
                        <h3 class="text-base font-bold text-gray-950 dark:text-white">{{ __('social.recent') }}</h3>
                        <ul class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($recent as $prepared)
                                <li class="flex flex-wrap items-center gap-3 py-3" wire:key="recent-{{ $prepared->id }}">
                                    <span class="min-w-0 flex-1 text-sm text-gray-950 dark:text-white">{{ trans_choice('social.studio.recent_item', count($prepared->items), ['date' => $prepared->date->format('d/m/Y'), 'format' => __('social.'.$prepared->format), 'count' => count($prepared->items)]) }}</span>
                                    <span class="flex flex-wrap gap-2">
                                        <x-filament::button size="sm" color="gray" icon="heroicon-o-eye" wire:click="openBatch('{{ $prepared->id }}')">{{ __('social.studio.open_batch') }}</x-filament::button>
                                        <x-filament::button size="sm" color="gray" tag="a" :href="route('social.zip', $prepared)" icon="heroicon-o-arrow-down-tray">{{ __('social.download') }}</x-filament::button>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </x-filament::section>

        @if ($isAdmin)
            <x-filament::section :heading="__('social.history')" icon="heroicon-o-clock" collapsible wire:poll.15s data-social-history>
                @php $publications = $this->publications(); @endphp
                @if ($publications->isEmpty())
                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('social.studio.history_empty') }}</p>
                @else
                    <ul class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($publications as $publication)
                            <li class="flex flex-col gap-2 py-3 text-sm text-gray-950 dark:text-white" wire:key="publication-{{ $publication->id }}">
                                <span>{{ $publication->created_at->timezone($timezone)->format('d/m H:i') }} · {{ ucfirst($publication->platform) }} · {{ __('social.part') }} {{ $publication->part + 1 }} · <strong>{{ __('social.'.$publication->status->value.'_status') }}</strong>@if ($publication->scheduled_at) · {{ __('social.schedule.expected', ['date' => $publication->scheduled_at->timezone($timezone)->format('d/m/Y H:i')]) }}@endif</span>
                                @if ($publication->error)<span class="text-gray-600 dark:text-gray-400">{{ $publication->error }}</span>@endif
                                <span class="flex flex-wrap gap-2">
                                    @if ($publication->status === \App\Enums\SocialPublicationStatus::Scheduled)
                                        <x-filament::button size="sm" color="gray" wire:click="reschedulePublication({{ $publication->id }})">{{ __('social.schedule.reschedule') }}</x-filament::button>
                                        <x-filament::button size="sm" color="gray" wire:click="cancelScheduledPublication({{ $publication->id }})">{{ __('social.schedule.cancel') }}</x-filament::button>
                                    @endif
                                    @if (in_array($publication->status, [\App\Enums\SocialPublicationStatus::Failed, \App\Enums\SocialPublicationStatus::Uncertain], true))
                                        <x-filament::button size="sm" color="gray" wire:click="retryPublication({{ $publication->id }})" wire:confirm="{{ __('social.retry_confirm') }}">{{ __('social.retry') }}</x-filament::button>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
