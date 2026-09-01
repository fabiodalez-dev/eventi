{{--
    Le fasce di prezzo di un evento o di una data: settore, prezzo, stato.

    **Lo stato è per fascia.** «Esaurito» qui significa che è finito quel
    settore, non la serata: è la cosa che `OccurrenceStatus::SoldOut` non sa
    dire, ed è la ragione per cui questa tabella esiste.

    Quale listino mostrare — quello dell'evento o quello della singola data —
    non lo decide questa vista: lo decide `App\Support\TicketTiers`, che è
    l'unico posto in cui la regola è scritta.
--}}
@props([
    'tiers',
    'heading' => null,
    'headingId' => 'fasce-prezzo',
    'note' => null,
    'level' => 'h2',
])

@php
    $rows = collect($tiers);
@endphp

@if ($rows->isNotEmpty())
    <section aria-labelledby="{{ $headingId }}" class="flex flex-col gap-3">
        <{{ $level }} id="{{ $headingId }}" class="font-display text-[clamp(1.25rem,1.8vw,1.75rem)] leading-none font-extrabold tracking-[-0.03em] uppercase">
            {{ $heading ?? __('events.detail.tickets') }}
        </{{ $level }}>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[20rem] border-collapse text-sm">
                <thead>
                    <tr class="border-b border-line text-left text-eyebrow text-ink-subtle">
                        <th scope="col" class="py-2 pr-3 font-display font-extrabold uppercase tracking-[0.12em]">{{ __('events.tiers.name') }}</th>
                        <th scope="col" class="py-2 pr-3 font-display font-extrabold uppercase tracking-[0.12em]">{{ __('events.tiers.price') }}</th>
                        <th scope="col" class="py-2 font-display font-extrabold uppercase tracking-[0.12em]">{{ __('events.tiers.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $tier)
                        @php
                            $amount = $tier->price === null ? null : (float) $tier->price;

                            $price = match (true) {
                                $amount === null => __('events.tiers.unknown_price'),
                                $amount <= 0.0 => __('events.tiers.free'),
                                default => (string) \Illuminate\Support\Number::currency(
                                    $amount,
                                    in: $tier->currency ?: 'EUR',
                                    locale: app()->getLocale(),
                                    precision: fmod($amount, 1.0) === 0.0 ? 0 : 2,
                                ),
                            };
                        @endphp

                        <tr class="border-b border-line/60 align-baseline">
                            <th scope="row" class="py-2.5 pr-3 text-left font-semibold text-ink">
                                {{ $tier->name }}

                                @if (filled($tier->note))
                                    <span class="block text-xs font-normal text-ink-subtle">{{ $tier->note }}</span>
                                @endif
                            </th>

                            <td class="py-2.5 pr-3 font-display font-extrabold text-ink">{{ $price }}</td>

                            <td class="py-2.5">
                                <span class="flex flex-wrap items-center gap-2">
                                    <x-badge :tone="$tier->isOnSale() ? 'brand' : 'muted'" size="sm">
                                        {{ $tier->status->label() }}
                                    </x-badge>

                                    @php $prevendita = \App\Support\SafeUrl::href($tier->url); @endphp
                                    @if ($tier->isOnSale() && $prevendita !== null)
                                        <a
                                            href="{{ $prevendita }}"
                                            rel="noopener noreferrer"
                                            target="_blank"
                                            class="text-xs font-semibold text-brand underline hover:no-underline"
                                        >
                                            {{ __('events.tiers.buy') }}
                                        </a>
                                    @endif
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($note !== null)
            <p class="text-xs text-ink-subtle">{{ $note }}</p>
        @endif
    </section>
@endif
