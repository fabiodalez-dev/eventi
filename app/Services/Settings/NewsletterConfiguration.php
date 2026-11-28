<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Enums\UserRole;
use App\Models\NotificationText;
use App\Models\User;
use App\Settings\NewsletterSettings;
use App\Support\Features;
use App\Support\NotificationTextCatalog;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * Le impostazioni della newsletter del fine settimana, per il pannello
 * (pagina «Newsletter») e per l'app della redazione: solo il super admin.
 *
 * Stavano dentro `App\Filament\Admin\Pages\Newsletter`. Le regole sono le
 * stesse: giorno da 1 a 7, ora `HH:MM`, da 1 a 30 eventi; i quattro testi
 * sono testi delle email (`notification_texts`), vuoti o uguali
 * all'originale tornano all'originale, e non possono usare segnaposti che
 * l'originale non ha.
 */
final class NewsletterConfiguration
{
    /** Il campo del modulo e la chiave del testo dell'email. */
    public const TEXTS = [
        'subject' => 'notifications.weekend.subject',
        'heading' => 'notifications.weekend.heading',
        'line' => 'notifications.weekend.line',
        'action' => 'notifications.actions.open_weekend',
    ];

    /** I tetti dei quattro testi, gli stessi dei campi del pannello. */
    public const TEXT_MAX = ['subject' => 200, 'heading' => 200, 'line' => 2000, 'action' => 120];

    public const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

    public const MAX_ITEMS_MIN = 1;

    public const MAX_ITEMS_MAX = 30;

    /** I giorni del pannello, da lunedì (1) a domenica (7). */
    public const WEEKDAYS = [1 => 'Lunedì', 2 => 'Martedì', 3 => 'Mercoledì', 4 => 'Giovedì', 5 => 'Venerdì', 6 => 'Sabato', 7 => 'Domenica'];

    public static function canManage(?User $user): bool
    {
        return $user?->hasRole(UserRole::SuperAdmin->value) === true;
    }

    /**
     * I valori di adesso: impostazioni, interruttore e i quattro testi
     * (riscritti o originali).
     *
     * @return array<string, mixed>
     */
    public static function values(): array
    {
        $values = app(NewsletterSettings::class)->toArray();
        $values['enabled'] = Features::newsletterActive();
        foreach (self::TEXTS as $field => $key) {
            $values[$field] = NotificationText::tutte()[$key] ?? NotificationTextCatalog::predefiniti()[$key];
        }

        return $values;
    }

    /**
     * I segnaposti sconosciuti, per campo del modulo.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, list<string>>
     */
    public static function unknownVariables(array $values): array
    {
        $defaults = NotificationTextCatalog::predefiniti();
        $unknown = [];
        foreach (self::TEXTS as $field => $key) {
            $extra = array_values(array_diff(NotificationTextCatalog::variabili((string) ($values[$field] ?? '')), NotificationTextCatalog::variabili($defaults[$key])));
            if ($extra !== []) {
                $unknown[$field] = $extra;
            }
        }

        return $unknown;
    }

    /**
     * Salva impostazioni, interruttore e testi in una transazione. Va
     * chiamato dopo i controlli.
     *
     * @param  array<string, mixed>  $values
     */
    public static function save(array $values, ?User $user): void
    {
        $before = self::auditable(self::values());
        $defaults = NotificationTextCatalog::predefiniti();
        DB::transaction(function () use ($values, $defaults, $user): void {
            $settings = app(NewsletterSettings::class);
            $settings->weekday = (int) $values['weekday'];
            $settings->time = (string) $values['time'];
            $settings->max_items = (int) $values['max_items'];
            $settings->save();
            Feature::for(Features::globalScope())->activate(Features::NEWSLETTER, (bool) $values['enabled']);
            foreach (self::TEXTS as $field => $key) {
                $value = trim((string) ($values[$field] ?? ''));
                if ($value === '' || $value === $defaults[$key]) {
                    NotificationText::where('key', $key)->delete();
                } else {
                    NotificationText::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $user?->getKey()]);
                }
            }
        });
        NotificationText::dimenticaLaCache();

        // La mail a tutti gli iscritti: chi la cambia, prima e dopo.
        SecurityLog::configurazione('newsletter_cambiata', ['oggetto' => 'newsletter', 'prima' => $before, 'dopo' => self::auditable(self::values())]);
    }

    /**
     * Interruttore, giorno, ora, quante proposte e i quattro testi.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function auditable(array $values): array
    {
        $keys = ['enabled', 'weekday', 'time', 'max_items', ...array_keys(self::TEXTS)];

        return array_combine($keys, array_map(static fn (string $key): mixed => $values[$key] ?? null, $keys));
    }
}
