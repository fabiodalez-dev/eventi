@props([
    'event',
    /* 'badge' dentro la card, 'text' nella scheda */
    'as' => 'badge',
])

@php
    $currency = $event->currency ?: 'EUR';
    $locale = app()->getLocale();

    $money = static function ($amount) use ($currency, $locale): string {
        $value = (float) $amount;

        return (string) \Illuminate\Support\Number::currency(
            $value,
            in: $currency,
            locale: $locale,
            precision: fmod($value, 1.0) === 0.0 ? 0 : 2,
        );
    };

    $min = $event->price_min !== null ? (float) $event->price_min : null;
    $max = $event->price_max !== null ? (float) $event->price_max : null;

    /* Un prezzo sconosciuto non si annuncia: la card resta senza etichetta
       invece di scrivere "prezzo non disponibile" (§8.6, stessa logica). */
    $label = match ($event->price_type) {
        \App\Enums\PriceType::Free => __('events.price.free'),
        \App\Enums\PriceType::Donation => __('events.price.donation'),
        \App\Enums\PriceType::Membership => $event->price_type->label(),
        \App\Enums\PriceType::Ticket => match (true) {
            $min !== null && $max !== null && $max > $min => __('events.price.range', ['min' => $money($min), 'max' => $money($max)]),
            $min !== null => $money($min),
            $max !== null => $money($max),
            default => null,
        },
        default => null,
    };

    $tone = $event->price_type === \App\Enums\PriceType::Free ? 'free' : 'neutral';
@endphp

@if ($label !== null)
    @if ($as === 'badge')
        <x-badge :tone="$tone" {{ $attributes }}>{{ $label }}</x-badge>
    @else
        <span {{ $attributes->class(['font-semibold', 'text-free' => $tone === 'free']) }}>{{ $label }}</span>
    @endif
@endif
