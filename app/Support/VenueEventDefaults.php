<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\PriceType;
use App\Models\Venue;

/**
 * Le abitudini di un locale, lette e riscritte in `venues.default_event_settings`
 * (§10.2, «precompilazione da `venue.default_event_settings`»).
 *
 * Lo schema lascia il campo libero; questa classe è il punto unico che ne
 * fissa la forma:
 *
 * ```json
 * {"category_id": 3, "price_type": "ticket", "price_min": 10, "start_hour": 21, "start_minute": 30, "duration_minutes": 180, "is_outdoor": false}
 * ```
 *
 * **Le abitudini si imparano, non si compilano.** Nessun modulo chiede a un
 * gestore quale sia la sua categoria abituale: sarebbe una schermata in più
 * per un dato che il sistema può dedurre. Le preferenze si aggiornano da sole
 * a ogni evento creato, così che il secondo evento costi meno del primo e il
 * decimo quasi nulla — che è la sostanza dei 90 secondi di §2.4.
 */
final class VenueEventDefaults
{
    /**
     * Ora abituale di inizio quando il locale non ne ha ancora una: le 21:00
     * è l'orario più frequente di una serata.
     */
    public const FALLBACK_HOUR = 21;

    public const FALLBACK_MINUTE = 0;

    /**
     * Valori con cui aprire il wizard.
     *
     * @return array<string, mixed>
     */
    public static function formDefaults(Venue $venue): array
    {
        $settings = self::settings($venue);

        $defaults = [
            'price_type' => PriceType::tryFrom((string) ($settings['price_type'] ?? ''))?->value,
            'category_id' => isset($settings['category_id']) ? (int) $settings['category_id'] : null,
            'price_min' => $settings['price_min'] ?? null,
            'is_outdoor' => (bool) ($settings['is_outdoor'] ?? false),
        ];

        return array_filter($defaults, static fn ($value): bool => $value !== null);
    }

    /**
     * L'ora abituale di inizio, come coppia ora/minuto.
     *
     * @return array{0: int, 1: int}
     */
    public static function startTime(Venue $venue): array
    {
        $settings = self::settings($venue);

        $hour = isset($settings['start_hour']) ? (int) $settings['start_hour'] : self::FALLBACK_HOUR;
        $minute = isset($settings['start_minute']) ? (int) $settings['start_minute'] : self::FALLBACK_MINUTE;

        return [
            max(0, min(23, $hour)),
            max(0, min(59, $minute)),
        ];
    }

    /**
     * Registra le scelte appena fatte come abitudini del locale.
     *
     * @param  array<string, mixed>  $data  i dati del modulo appena salvato
     */
    public static function remember(Venue $venue, array $data, ?int $startHour = null, ?int $startMinute = null): void
    {
        $settings = self::settings($venue);

        if (isset($data['category_id'])) {
            $settings['category_id'] = (int) $data['category_id'];
        }

        if (isset($data['price_type'])) {
            $priceType = $data['price_type'] instanceof PriceType
                ? $data['price_type']
                : PriceType::tryFrom((string) $data['price_type']);

            if ($priceType instanceof PriceType) {
                $settings['price_type'] = $priceType->value;
                $settings['price_min'] = $priceType === PriceType::Ticket ? ($data['price_min'] ?? null) : null;
            }
        }

        if (array_key_exists('is_outdoor', $data)) {
            $settings['is_outdoor'] = (bool) $data['is_outdoor'];
        }

        if ($startHour !== null) {
            $settings['start_hour'] = $startHour;
            $settings['start_minute'] = $startMinute ?? 0;
        }

        $venue->default_event_settings = $settings;
        $venue->save();
    }

    /**
     * @return array<string, mixed>
     */
    private static function settings(Venue $venue): array
    {
        $settings = $venue->default_event_settings;

        return is_array($settings) ? $settings : [];
    }
}
