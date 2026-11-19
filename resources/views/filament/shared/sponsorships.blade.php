@use('App\Enums\PromotionMode')
@use('App\Enums\SponsorshipOrderStatus')
@use('App\Enums\SponsorshipPlacement')
@use('App\Enums\SponsorshipStatus')
@use('App\Filament\Support\PosterColumn')
@use('Illuminate\Support\Number')
@use('Illuminate\Support\Str')
<x-filament-panels::page>
    @php
        $tz = $this->timezone();
        $online = $this->paysOnline();
        $canPurchase = $this->canPurchase();
        $grants = $this->grants();
        $selectable = $this->selectableGrants();
        $ordini = $this->orders();
        $campagne = $this->sponsorships();
        $totali = $this->totals();
        $money = fn (?int $cents): string => $cents === null || $cents < 1 ? __('promotions.request.price_to_agree') : Number::currency($cents / 100, in: 'EUR', locale: 'it');
        $months = fn (int $n): string => trans_choice('promotions.page.months', $n, ['count' => $n]);
        $card = 'rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-white/5';
        $muted = 'text-sm text-gray-600 dark:text-gray-400';
        $poster = 'h-16 w-12 shrink-0 rounded-md bg-gray-100 object-cover dark:bg-white/10';
    @endphp

    <div class="flex flex-col gap-6" data-sponsorship-page>
        {{-- Come funziona: tre passi e la strada di pagamento di oggi. --}}
        <x-filament::section :heading="__('promotions.page.how_title')" icon="heroicon-o-light-bulb" data-sponsorship-how>
            <ol class="flex flex-col gap-3">
                @foreach (['how_step_1', 'how_step_2', $online ? 'how_step_3_online' : 'how_step_3_request'] as $i => $step)
                    <li class="flex items-start gap-3">
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-primary-50 text-sm font-semibold text-primary-700 dark:bg-primary-500/15 dark:text-primary-300">{{ $i + 1 }}</span>
                        <span class="pt-0.5 text-sm text-gray-700 dark:text-gray-200">{{ __('promotions.page.'.$step) }}</span>
                    </li>
                @endforeach
            </ol>
            <p class="mt-4 {{ $muted }}">{{ __('promotions.page.how_disclosure') }}</p>
        </x-filament::section>

        {{-- Un periodo gia' attivo con eventi scelti: si mettono in evidenza subito. --}}
        @if ($selectable->isNotEmpty())
            @php
                $attivi = $this->activeEventIds();
                $eventiAttivabili = $this->sponsorableEvents();
            @endphp
            <x-filament::section :heading="__('promotions.page.active_title')" :description="__('promotions.page.active_lead')" icon="heroicon-o-bolt" data-sponsorship-active>
                @if ($selectable->count() > 1)
                    <div class="mb-4 flex flex-wrap gap-2" role="radiogroup" aria-label="{{ __('promotions.page.active_title') }}">
                        @foreach ($selectable as $g)
                            <x-filament::button size="sm" :color="$grantId === $g->id ? 'primary' : 'gray'" :outlined="$grantId !== $g->id"
                                wire:click="chooseGrant({{ $g->id }})" role="radio" aria-checked="{{ $grantId === $g->id ? 'true' : 'false' }}">
                                {{ $g->placement->label() }} · {{ __('promotions.page.active_until', ['date' => $g->ends_at->timezone($tz)->format('d/m/Y')]) }}
                            </x-filament::button>
                        @endforeach
                    </div>
                @else
                    <p class="mb-4 {{ $muted }}">{{ $selectable->first()->placement->label() }} · {{ __('promotions.page.active_until', ['date' => $selectable->first()->ends_at->timezone($tz)->format('d/m/Y')]) }}</p>
                @endif

                @if ($eventiAttivabili->isEmpty())
                    <p class="{{ $muted }}">{{ __('promotions.page.no_events_body') }}</p>
                @else
                    <ul class="flex flex-col gap-3">
                        @foreach ($eventiAttivabili as $evento)
                            <li class="{{ $card }} flex items-center gap-3 p-3" wire:key="active-{{ $evento->id }}">
                                <img src="{{ PosterColumn::url($evento) }}" alt="" loading="lazy" class="{{ $poster }}">
                                <div class="min-w-0 flex-1">
                                    <p class="line-clamp-2 font-semibold text-gray-950 dark:text-white">{{ $evento->title }}</p>
                                    @if ($evento->next_starts_at)
                                        <p class="{{ $muted }}">{{ __('promotions.page.next_date', ['date' => \Illuminate\Support\Carbon::parse($evento->next_starts_at, 'UTC')->timezone($tz)->translatedFormat('D j M · H:i')]) }}</p>
                                    @endif
                                </div>
                                @if (in_array($evento->id, $attivi, true))
                                    <x-filament::badge color="success" icon="heroicon-m-check">{{ __('promotions.page.active_on') }}</x-filament::badge>
                                @else
                                    <x-filament::button size="sm" wire:click="activate({{ $evento->id }})" wire:loading.attr="disabled">{{ __('promotions.page.activate') }}</x-filament::button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>
        @endif

        {{-- Una sponsorizzazione nuova, in quattro passi. --}}
        @if (! $canPurchase)
            <x-filament::callout icon="heroicon-o-lock-closed" color="gray" :heading="__('promotions.page.not_owner_title')"
                :description="__($this->isVenue() ? 'promotions.page.not_owner_body_venue' : 'promotions.page.not_owner_body_organizer')" />
        @else
            @php
                $eventi = $this->sponsorableEvents();
                $giaSponsorizzati = $this->sponsoredIds($eventi);
                $totaleEventi = $this->sponsorableCount();
                $durate = $this->durations();
                $scelta = $this->chosenDuration();
                $eventoScelto = $this->chosenEvent();
            @endphp

            <x-filament::section :heading="__('promotions.page.step_what')" :description="__('promotions.page.step_what_lead')" data-sponsorship-step="what">
                <div class="flex flex-col gap-3" role="radiogroup" aria-label="{{ __('promotions.page.step_what') }}">
                    @foreach (['event' => 'heroicon-o-ticket', 'all' => 'heroicon-o-squares-2x2'] as $value => $icon)
                        @php $on = $scope === $value; @endphp
                        <button type="button" wire:click="$set('scope', '{{ $value }}')" role="radio" aria-checked="{{ $on ? 'true' : 'false' }}"
                            @class([$card, 'flex w-full items-start gap-3 p-4 text-start transition', 'ring-2 ring-primary-500 dark:ring-primary-400' => $on, 'hover:bg-gray-50 dark:hover:bg-white/10' => ! $on])>
                            <x-filament::icon :icon="$icon" @class(['mt-0.5 size-6 shrink-0', 'text-primary-600 dark:text-primary-400' => $on, 'text-gray-400 dark:text-gray-500' => ! $on]) />
                            <span class="min-w-0 flex-1">
                                <span class="block font-semibold text-gray-950 dark:text-white">{{ __('promotions.page.scope_'.$value) }}</span>
                                <span class="block {{ $muted }}">{{ __('promotions.page.scope_'.$value.'_hint') }}</span>
                            </span>
                            <x-filament::icon :icon="$on ? 'heroicon-s-check-circle' : 'heroicon-o-stop-circle'" @class(['size-6 shrink-0', 'text-primary-600 dark:text-primary-400' => $on, 'text-gray-300 dark:text-gray-600' => ! $on]) />
                        </button>
                    @endforeach
                </div>

                @if ($scope === 'event')
                    <div class="mt-6 flex flex-col gap-3" data-sponsorship-events>
                        @if ($totaleEventi === 0)
                            <x-filament::empty-state icon="heroicon-o-calendar" :heading="__('promotions.page.no_events_title')" :description="__('promotions.page.no_events_body')" :contained="false" />
                        @else
                            <label for="sponsorship-search" class="text-sm font-medium text-gray-950 dark:text-white">{{ __('promotions.page.search') }}</label>
                            <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                                <x-filament::input id="sponsorship-search" type="search" wire:model.live.debounce.400ms="search" maxlength="80" :placeholder="__('promotions.page.search_placeholder')" />
                            </x-filament::input.wrapper>

                            @if ($eventi->isEmpty())
                                <p class="{{ $muted }}">{{ __('promotions.page.no_results') }}</p>
                            @else
                                <ul class="flex flex-col gap-3" role="radiogroup" aria-label="{{ __('promotions.event') }}">
                                    @foreach ($eventi as $evento)
                                        @php $on = $eventId === $evento->id; @endphp
                                        <li wire:key="pick-{{ $evento->id }}">
                                            <button type="button" wire:click="selectEvent({{ $evento->id }})" role="radio" aria-checked="{{ $on ? 'true' : 'false' }}" data-sponsorship-event="{{ $evento->id }}"
                                                @class([$card, 'flex w-full items-center gap-3 p-3 text-start transition', 'ring-2 ring-primary-500 dark:ring-primary-400' => $on, 'hover:bg-gray-50 dark:hover:bg-white/10' => ! $on])>
                                                <img src="{{ PosterColumn::url($evento) }}" alt="" loading="lazy" class="{{ $poster }}">
                                                <span class="min-w-0 flex-1">
                                                    <span class="line-clamp-2 font-semibold text-gray-950 dark:text-white">{{ $evento->title }}</span>
                                                    @if ($evento->next_starts_at)
                                                        <span class="block {{ $muted }}">{{ __('promotions.page.next_date', ['date' => \Illuminate\Support\Carbon::parse($evento->next_starts_at, 'UTC')->timezone($tz)->translatedFormat('D j M · H:i')]) }}</span>
                                                    @endif
                                                    <span class="mt-1 flex flex-wrap gap-1">
                                                        <x-filament::badge color="success" size="sm">{{ __('promotions.page.published') }}</x-filament::badge>
                                                        @if (in_array($evento->id, $giaSponsorizzati, true))
                                                            <x-filament::badge color="warning" size="sm">{{ __('promotions.page.already') }}</x-filament::badge>
                                                        @endif
                                                    </span>
                                                </span>
                                                <x-filament::icon :icon="$on ? 'heroicon-s-check-circle' : 'heroicon-o-stop-circle'" @class(['size-6 shrink-0', 'text-primary-600 dark:text-primary-400' => $on, 'text-gray-300 dark:text-gray-600' => ! $on]) />
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                                @if ($this->hasMoreEvents())
                                    <div><x-filament::button color="gray" outlined size="sm" wire:click="showMoreEvents">{{ __('promotions.page.more') }}</x-filament::button></div>
                                @endif
                            @endif
                        @endif
                    </div>
                @endif
            </x-filament::section>

            <x-filament::section :heading="__('promotions.page.step_where')" :description="__('promotions.page.step_where_lead')" data-sponsorship-step="where">
                <div class="flex flex-col gap-3" role="radiogroup" aria-label="{{ __('promotions.page.step_where') }}">
                    @foreach (SponsorshipPlacement::cases() as $formato)
                        @php
                            $on = $placement === $formato->value;
                            $da = collect(app(\App\Services\Sponsorship\SponsorshipOrders::class)->durations($formato))->min('amount_cents');
                        @endphp
                        <button type="button" wire:click="selectPlacement('{{ $formato->value }}')" role="radio" aria-checked="{{ $on ? 'true' : 'false' }}" data-sponsorship-format="{{ $formato->value }}"
                            @class([$card, 'flex w-full items-center gap-4 p-3 text-start transition', 'ring-2 ring-primary-500 dark:ring-primary-400' => $on, 'hover:bg-gray-50 dark:hover:bg-white/10' => ! $on])>
                            @include('filament.shared.sponsorship-format-preview', ['placement' => $formato->value])
                            <span class="min-w-0 flex-1">
                                <span class="block font-semibold text-gray-950 dark:text-white">{{ $formato->label() }}</span>
                                <span class="block {{ $muted }}">{{ __('promotions.formats.'.$formato->value) }}</span>
                                <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">
                                    {{ trans_choice('promotions.formats.rotation', $formato->limit(), ['count' => $formato->limit()]) }}
                                    @if ($da) · {{ __('promotions.formats.from', ['price' => $money((int) $da)]) }} @endif
                                </span>
                            </span>
                            <x-filament::icon :icon="$on ? 'heroicon-s-check-circle' : 'heroicon-o-stop-circle'" @class(['size-6 shrink-0', 'text-primary-600 dark:text-primary-400' => $on, 'text-gray-300 dark:text-gray-600' => ! $on]) />
                        </button>
                    @endforeach
                </div>
            </x-filament::section>

            <x-filament::section :heading="__('promotions.page.step_time')" :description="__('promotions.page.step_time_lead')" data-sponsorship-step="time">
                @if ($durate === [])
                    <p class="{{ $muted }}">{{ __('promotions.page.choose_format_first') }}</p>
                @else
                    <div class="flex flex-wrap gap-3" role="radiogroup" aria-label="{{ __('promotions.page.step_time') }}">
                        @foreach ($durate as $d)
                            @php $on = $duration === $d['key']; @endphp
                            <button type="button" wire:click="selectDuration('{{ $d['key'] }}')" role="radio" aria-checked="{{ $on ? 'true' : 'false' }}" data-sponsorship-duration="{{ $d['key'] }}"
                                @class([$card, 'flex min-h-12 min-w-28 flex-col items-start px-4 py-2 text-start transition', 'ring-2 ring-primary-500 dark:ring-primary-400' => $on, 'hover:bg-gray-50 dark:hover:bg-white/10' => ! $on])>
                                <span class="font-semibold text-gray-950 dark:text-white">{{ $months($d['months']) }}</span>
                                <span class="text-sm text-gray-600 dark:text-gray-400">{{ $money($d['amount_cents']) }}</span>
                            </button>
                        @endforeach
                    </div>
                    @if ($durate[0]['price_id'] !== null)
                        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('promotions.page.price_vat') }}</p>
                    @endif
                @endif
            </x-filament::section>

            <x-filament::section :heading="__('promotions.page.step_summary')" data-sponsorship-step="summary">
                @if (! $this->readyToSubmit())
                    <p class="{{ $muted }}">{{ __('promotions.page.incomplete_hint') }}</p>
                @else
                    @php $viaStripe = $online && $scelta['price_id'] !== null; @endphp
                    <dl class="flex flex-col divide-y divide-gray-200 dark:divide-white/10">
                        <div class="flex flex-col gap-0.5 py-2 sm:flex-row sm:justify-between sm:gap-4">
                            <dt class="{{ $muted }}">{{ __('promotions.page.summary_what') }}</dt>
                            <dd class="font-medium text-gray-950 dark:text-white sm:text-end">{{ $eventoScelto?->title ?? __('promotions.page.scope_all') }}</dd>
                        </div>
                        <div class="flex flex-col gap-0.5 py-2 sm:flex-row sm:justify-between sm:gap-4">
                            <dt class="{{ $muted }}">{{ __('promotions.page.summary_where') }}</dt>
                            <dd class="font-medium text-gray-950 dark:text-white sm:text-end">{{ SponsorshipPlacement::from($placement)->label() }}</dd>
                        </div>
                        <div class="flex flex-col gap-0.5 py-2 sm:flex-row sm:justify-between sm:gap-4">
                            <dt class="{{ $muted }}">{{ __('promotions.page.summary_time') }}</dt>
                            <dd class="font-medium text-gray-950 dark:text-white sm:text-end">{{ $months($scelta['months']) }} · {{ __('promotions.page.summary_from_approval') }}</dd>
                        </div>
                        <div class="flex flex-col gap-0.5 py-2 sm:flex-row sm:justify-between sm:gap-4">
                            <dt class="{{ $muted }}">{{ __('promotions.page.summary_price') }}</dt>
                            <dd class="font-medium text-gray-950 dark:text-white sm:text-end">{{ $money($scelta['amount_cents']) }}</dd>
                        </div>
                        <div class="flex flex-col gap-0.5 py-2 sm:flex-row sm:justify-between sm:gap-4">
                            <dt class="{{ $muted }}">{{ __('promotions.page.summary_payment') }}</dt>
                            <dd class="font-medium text-gray-950 dark:text-white sm:text-end">{{ __($viaStripe ? 'promotions.page.payment_online' : 'promotions.page.payment_request') }}</dd>
                        </div>
                    </dl>

                    @unless ($viaStripe)
                        <div class="mt-4 flex flex-col gap-2">
                            <label for="sponsorship-note" class="text-sm font-medium text-gray-950 dark:text-white">{{ __('promotions.page.note_label') }}</label>
                            <x-filament::input.wrapper :valid="! $errors->has('note')">
                                <textarea id="sponsorship-note" wire:model="note" rows="3" maxlength="1000"
                                    class="block w-full resize-y border-none bg-transparent px-3 py-2 text-base text-gray-950 placeholder:text-gray-400 focus:ring-0 sm:text-sm dark:text-white dark:placeholder:text-gray-500"
                                    placeholder="{{ __('promotions.page.note_placeholder') }}"></textarea>
                            </x-filament::input.wrapper>
                            @error('note')<p class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>@enderror
                        </div>
                    @endunless

                    <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center">
                        <x-filament::button size="lg" wire:click="submitOrder" wire:loading.attr="disabled" :icon="$viaStripe ? 'heroicon-o-credit-card' : 'heroicon-o-paper-airplane'" data-sponsorship-submit>
                            {{ __($viaStripe ? 'promotions.page.submit_online' : 'promotions.page.submit_request') }}
                        </x-filament::button>
                        <p class="{{ $muted }}">{{ __($viaStripe ? 'promotions.page.submit_online_hint' : 'promotions.page.submit_request_hint') }}</p>
                    </div>
                @endif
            </x-filament::section>
        @endif

        {{-- Richieste e ordini, come card: niente tabelle che scorrono sul telefono. --}}
        <x-filament::section :heading="__('promotions.orders.yours')" data-sponsorship-orders>
            @if ($ordini->isEmpty())
                <p class="{{ $muted }}">{{ __('promotions.page.orders_none') }}</p>
            @else
                <ul class="flex flex-col gap-3">
                    @foreach ($ordini as $ordine)
                        <li class="{{ $card }} flex flex-col gap-3 p-3" data-sponsorship-order="{{ $ordine->id }}" wire:key="order-{{ $ordine->id }}">
                            <div class="flex items-start gap-3">
                                @if ($ordine->event)
                                    <img src="{{ PosterColumn::url($ordine->event) }}" alt="" loading="lazy" class="{{ $poster }}">
                                @endif
                                <div class="min-w-0 flex-1">
                                    <p class="font-semibold text-gray-950 dark:text-white">{{ $ordine->placement->label() }} · {{ $months($ordine->months) }}</p>
                                    <p class="{{ $muted }}">{{ $ordine->event?->title ?? __('promotions.page.scope_all') }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $ordine->created_at?->timezone($tz)->format('d/m/Y H:i') }} · {{ $money($ordine->amount_cents) }}</p>
                                </div>
                                <x-filament::badge :color="$ordine->status->color()" class="shrink-0">{{ $ordine->status->label() }}</x-filament::badge>
                            </div>
                            @if ($ordine->status === SponsorshipOrderStatus::Rejected && filled($ordine->decision_note))
                                <p class="{{ $muted }}">{{ __('promotions.page.reason', ['reason' => Str::before($ordine->decision_note, "\n")]) }}</p>
                            @elseif ($ordine->status === SponsorshipOrderStatus::Requested)
                                <p class="{{ $muted }}">{{ __('promotions.page.requested_hint') }}</p>
                            @endif
                            @if ($canPurchase && $ordine->status === SponsorshipOrderStatus::PendingPayment)
                                <div class="flex flex-wrap gap-2">
                                    <x-filament::button size="sm" wire:click="retry({{ $ordine->id }})">{{ __('promotions.orders.retry') }}</x-filament::button>
                                    <x-filament::button size="sm" color="gray" wire:click="cancelOrder({{ $ordine->id }})" wire:confirm="{{ __('promotions.orders.cancel_confirm') }}">{{ __('promotions.orders.cancel') }}</x-filament::button>
                                </div>
                            @elseif ($canPurchase && $ordine->status === SponsorshipOrderStatus::Requested)
                                <div class="flex flex-wrap gap-2">
                                    <x-filament::button size="sm" color="gray" wire:click="withdraw({{ $ordine->id }})" wire:confirm="{{ __('promotions.request.withdraw_confirm') }}">{{ __('promotions.request.withdraw') }}</x-filament::button>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>

        {{-- I periodi concessi o pagati, anche scaduti. --}}
        @if ($grants->isNotEmpty())
            <x-filament::section :heading="__('promotions.page.grants_title')" collapsible :collapsed="$selectable->isNotEmpty()" data-sponsorship-grants>
                <ul class="flex flex-col gap-3">
                    @foreach ($grants as $grant)
                        <li class="{{ $card }} flex flex-col gap-1 p-3">
                            <div class="flex items-start justify-between gap-3">
                                <p class="font-semibold text-gray-950 dark:text-white">{{ $grant->placement->label() }}</p>
                                <x-filament::badge color="gray" class="shrink-0">{{ $grant->statusLabel() }}</x-filament::badge>
                            </div>
                            <p class="{{ $muted }}">{{ $grant->mode === PromotionMode::Automatic ? __('promotions.page.scope_all') : __('promotions.page.scope_event_many') }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $grant->starts_at->timezone($tz)->format('d/m/Y') }} → {{ $grant->ends_at->timezone($tz)->format('d/m/Y') }} ·
                                {{ $grant->complimentary ? __('promotions.complimentary') : ($grant->paid_at ? __('promotions.paid').' '.$grant->paid_at->timezone($tz)->format('d/m/Y') : __('promotions.awaiting_payment')) }}
                            </p>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        {{-- Le campagne sui propri eventi, con i risultati. --}}
        <x-filament::section :heading="__('promotions.page.campaigns_title')" :description="__('promotions.page.campaigns_lead')" data-sponsorship-campaigns>
            <dl class="mb-4 flex flex-wrap gap-x-8 gap-y-2">
                <div><dt class="{{ $muted }}">{{ __('promotions.views') }}</dt><dd class="text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($totali['impressions'], 0, ',', '.') }}</dd></div>
                <div><dt class="{{ $muted }}">{{ __('promotions.clicks') }}</dt><dd class="text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($totali['clicks'], 0, ',', '.') }}</dd></div>
            </dl>
            @if ($campagne->isEmpty())
                <p class="{{ $muted }}">{{ __('sponsorships.venue.none') }}</p>
            @else
                <ul class="flex flex-col gap-3">
                    @foreach ($campagne as $campagna)
                        @php $fase = $campagna->phase(); @endphp
                        <li class="{{ $card }} flex items-start gap-3 p-3" wire:key="campaign-{{ $campagna->id }}">
                            <img src="{{ PosterColumn::url($campagna->event) }}" alt="" loading="lazy" class="{{ $poster }}">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="min-w-0 font-semibold text-gray-950 dark:text-white">{{ $campagna->event?->title }}</p>
                                    <x-filament::badge :color="$fase->color()" class="shrink-0">{{ $fase->label() }}</x-filament::badge>
                                </div>
                                <p class="{{ $muted }}">{{ $campagna->placement->label() }} · {{ __('promotions.page.ends', ['date' => $campagna->ends_at?->timezone($tz)->format('d/m/Y H:i')]) }}</p>
                                <p class="text-sm tabular-nums text-gray-700 dark:text-gray-300">{{ __('promotions.page.results', ['views' => number_format((int) $campagna->impressions, 0, ',', '.'), 'clicks' => number_format((int) $campagna->clicks, 0, ',', '.')]) }}</p>
                                @if ($campagna->grant?->mode === PromotionMode::Selected && $campagna->status === SponsorshipStatus::Active)
                                    <div class="mt-2">
                                        <x-filament::button size="sm" color="gray" wire:click="stop({{ $campagna->id }})" wire:confirm="{{ __('promotions.stop_confirm') }}">{{ __('promotions.stop') }}</x-filament::button>
                                    </div>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
                @if ($campagne->hasPages())
                    <x-filament::pagination :paginator="$campagne" class="mt-4" />
                @endif
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
