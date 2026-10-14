<x-filament-panels::page>
    @php
        $campagne = $this->sponsorships();
        $ordini = $this->orders();
        $totali = $this->totals();
    @endphp

    <section>
        <h2 class="font-bold">{{ __('promotions.title') }}</h2>
        @forelse ($this->grants() as $grant)
            <p class="mt-2">{{ $grant->mode->label() }} · {{ $grant->placement->label() }} · {{ $grant->starts_at->timezone('Europe/Rome')->format('d/m/Y H:i') }} → {{ $grant->ends_at->timezone('Europe/Rome')->format('d/m/Y H:i') }}
                · {{ $grant->complimentary ? __('promotions.complimentary') : ($grant->paid_at ? __('promotions.paid').' '.$grant->paid_at->format('d/m/Y') : __('promotions.awaiting_payment')) }}
                · {{ $grant->statusLabel() }}
            </p>
        @empty
            <p>{{ __('promotions.no_grants') }}</p>
        @endforelse
    </section>

    <section data-sponsorship-orders>
        <h2 class="font-bold">{{ __('promotions.orders.yours') }}</h2>
        @if ($ordini->isEmpty())
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('promotions.orders.none') }}</p>
        @else
            <div class="fi-section mt-2 overflow-x-auto bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 text-left text-xs font-semibold uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-3">{{ __('promotions.orders.date') }}</th>
                            <th class="px-4 py-3">{{ __('promotions.placement') }}</th>
                            <th class="px-4 py-3">{{ __('promotions.orders.duration') }}</th>
                            <th class="px-4 py-3">{{ __('promotions.orders.mode') }}</th>
                            <th class="px-4 py-3">{{ __('promotions.amount') }}</th>
                            <th class="px-4 py-3">{{ __('admin.fields.status') }}</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($ordini as $ordine)
                            <tr data-sponsorship-order="{{ $ordine->id }}">
                                <td class="px-4 py-3">{{ $ordine->created_at?->timezone('Europe/Rome')->format('d/m/Y H:i') }}</td>
                                <td class="px-4 py-3">{{ $ordine->placement->label() }}</td>
                                <td class="px-4 py-3">{{ __('promotions.orders.months_short', ['months' => $ordine->months]) }}</td>
                                <td class="px-4 py-3">{{ $ordine->mode->label() }}</td>
                                <td class="px-4 py-3">{{ $ordine->formattedAmount() }}</td>
                                <td class="px-4 py-3"><x-filament::badge :color="$ordine->status->color()">{{ $ordine->status->label() }}</x-filament::badge></td>
                                <td class="px-4 py-3">
                                    @if ($ordine->status === \App\Enums\SponsorshipOrderStatus::PendingPayment && $this->canPurchase())
                                        <div class="flex flex-wrap gap-2">
                                            <x-filament::button size="sm" wire:click="retry({{ $ordine->id }})">{{ __('promotions.orders.retry') }}</x-filament::button>
                                            <x-filament::button size="sm" color="gray" wire:click="cancelOrder({{ $ordine->id }})" wire:confirm="{{ __('promotions.orders.cancel_confirm') }}">{{ __('promotions.orders.cancel') }}</x-filament::button>
                                        </div>
                                    @elseif ($ordine->status === \App\Enums\SponsorshipOrderStatus::Rejected && filled($ordine->decision_note))
                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ \Illuminate\Support\Str::before($ordine->decision_note, "\n") }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <p>{{ __('promotions.views') }}: {{ number_format($totali['impressions'], 0, ',', '.') }} · {{ __('promotions.clicks') }}: {{ number_format($totali['clicks'], 0, ',', '.') }}</p>

    @if ($campagne->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('sponsorships.venue.none') }}</p>
    @else
        <div class="fi-section overflow-x-auto bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <table class="w-full text-sm">
                <thead class="border-b border-gray-200 text-left text-xs font-semibold uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3">{{ __('sponsorships.admin.fields.event') }}</th>
                        <th class="px-4 py-3">{{ __('sponsorships.admin.fields.advertiser_name') }}</th>
                        <th class="px-4 py-3">{{ __('sponsorships.admin.fields.placement') }}</th>
                        <th class="px-4 py-3">{{ __('sponsorships.admin.fields.ends_at') }}</th>
                        <th class="px-4 py-3">{{ __('promotions.views') }}</th>
                        <th class="px-4 py-3">{{ __('promotions.clicks') }}</th>
                        <th class="px-4 py-3">{{ __('admin.fields.status') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($campagne as $campagna)
                        <tr>
                            <td class="px-4 py-3 font-medium">{{ $campagna->event?->title }}</td>
                            <td class="px-4 py-3">{{ $campagna->advertiser_name }}</td>
                            <td class="px-4 py-3">{{ $campagna->placement->label() }}</td>
                            <td class="px-4 py-3">{{ $campagna->ends_at?->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-3">{{ $campagna->impressions }}</td>
                            <td class="px-4 py-3">{{ $campagna->clicks }}</td>
                            <td class="px-4 py-3">{{ $campagna->phase()->label() }}</td>
                            <td class="px-4 py-3">
                                @if ($campagna->grant?->mode === \App\Enums\PromotionMode::Selected && $campagna->status === \App\Enums\SponsorshipStatus::Active)
                                    <x-filament::button color="gray" wire:click="stop({{ $campagna->id }})" wire:confirm="{{ __('promotions.stop_confirm') }}">{{ __('promotions.stop') }}</x-filament::button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $campagne->links() }}
    @endif
</x-filament-panels::page>
