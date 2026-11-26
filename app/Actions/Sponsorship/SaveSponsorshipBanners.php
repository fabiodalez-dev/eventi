<?php

declare(strict_types=1);

namespace App\Actions\Sponsorship;

use App\Models\User;
use App\Settings\SponsorshipBannerSettings;
use App\Support\SecurityLog;

/**
 * «Banner sponsorizzati»: i due interruttori, sito e app Android. Una regola
 * sola per la pagina del pannello e per l'app della redazione: salva e, se
 * qualcosa è cambiato, lo scrive nel registro di sicurezza con le chiavi
 * cambiate (prima e dopo).
 */
final class SaveSponsorshipBanners
{
    public const KEYS = ['web_enabled', 'android_enabled'];

    public function __construct(private readonly SponsorshipBannerSettings $settings) {}

    /** @param  array<string, mixed>  $values */
    public function __invoke(array $values, ?User $actor): SponsorshipBannerSettings
    {
        $changes = [];
        $next = [];
        foreach (self::KEYS as $key) {
            $before = (bool) $this->settings->{$key};
            $after = array_key_exists($key, $values) ? (bool) $values[$key] : $before;
            $next[$key] = $after;
            if ($before !== $after) {
                $changes[$key] = ['prima' => $before, 'dopo' => $after];
            }
        }

        $this->settings->fill($next)->save();

        if ($changes !== []) {
            SecurityLog::scrivi('banner_sponsorizzazioni_modificato', dettagli: ['modifiche' => $changes], causa: $actor);
        }

        return $this->settings;
    }
}
