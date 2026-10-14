<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SponsorshipPlacement;
use App\Models\SponsorshipPrice;
use Illuminate\Database\Seeder;

/**
 * Il listino di partenza: prezzi segnaposto, da correggere dalla redazione.
 * Idempotente: aggiorna le voci esistenti invece di duplicarle.
 */
class SponsorshipPriceSeeder extends Seeder
{
    /** @var array<string, int> prezzo mensile in centesimi per posizione */
    private const MONTHLY = [
        'home_hero' => 15000,
        'home_card' => 9000,
        'list_top' => 6000,
        'map_sheet' => 4000,
    ];

    public function run(): void
    {
        foreach (SponsorshipPlacement::cases() as $placement) {
            $monthly = self::MONTHLY[$placement->value];
            foreach ([1 => 1.0, 3 => 2.7, 6 => 5.0, 12 => 9.0] as $months => $factor) {
                SponsorshipPrice::query()->updateOrCreate(
                    ['placement' => $placement->value, 'months' => $months],
                    ['amount_cents' => (int) round($monthly * $factor), 'currency' => 'EUR', 'is_active' => true],
                );
            }
        }
    }
}
