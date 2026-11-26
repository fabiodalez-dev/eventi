<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Enums\ConsentCategory;
use App\Models\ConsentScript;
use App\Models\CookieDeclaration;
use App\Models\Redirect;
use App\Models\User;
use App\Services\Settings\NewsletterConfiguration;
use App\Services\Settings\NotificationTextEditor;
use App\Settings\FrontendCacheSettings;
use App\Settings\MailSettings;
use App\Support\NotificationTextCatalog;
use Illuminate\Support\Carbon;

/**
 * Le risposte dell'area «Configurazione tecnica» della redazione nell'app
 * (R13, R-SY-08…14): testi delle email, posta, newsletter, script di
 * consenso, cookie, indirizzi cambiati, cache.
 *
 * **I segreti non escono mai** (decisione 5): la password SMTP e quella di
 * Redis diventano `has_password`. Le pagine del pannello che le contengono
 * si scrivono solo dal sito; l'app le legge e fa le azioni operative.
 */
final class EditorialSettings
{
    // ---------------------------------------------------------- testi email

    /**
     * I testi per gruppo, come le schede della pagina «Testi delle email».
     *
     * @return array<string, mixed>
     */
    public function texts(User $user): array
    {
        $groups = [];
        foreach (NotificationTextEditor::groups($user) as $name => $group) {
            $texts = [];
            foreach ($group['testi'] as $key => $text) {
                $texts[] = $this->textRow($key, $text['predefinito'], $text['attuale'], $text['modificato']);
            }
            $groups[] = ['key' => $name, 'label' => $group['etichetta'], 'texts' => $texts];
        }

        return [
            'groups' => $groups,
            /* Il super admin vede anche i testi della newsletter. */
            'newsletter_included' => NotificationTextEditor::reserved($user) === [],
        ];
    }

    /**
     * Un testo con originale, valore attuale, segnaposti, esempio e revisione.
     *
     * @return array<string, mixed>
     */
    public function textRow(string $key, string $default, string $current, bool $modified): array
    {
        return [
            'key' => $key,
            'label' => NotificationTextEditor::label($key),
            'default' => $default,
            'value' => $current,
            'modified' => $modified,
            'variables' => $this->variables($default),
            'preview' => NotificationTextEditor::preview($key, $current),
            'rows' => NotificationTextEditor::rows($default),
            'revision' => NotificationTextEditor::revision($key),
        ];
    }

    /**
     * Il testo `$key` com'è adesso, o `null` se la persona non lo vede.
     *
     * @return array<string, mixed>|null
     */
    public function text(User $user, string $key): ?array
    {
        foreach (NotificationTextEditor::groups($user) as $group) {
            if (isset($group['testi'][$key])) {
                $text = $group['testi'][$key];

                return $this->textRow($key, $text['predefinito'], $text['attuale'], $text['modificato']);
            }
        }

        return null;
    }

    /**
     * I segnaposti di un originale, con il nome che il pannello mostra sul
     * pulsante «Inserisci».
     *
     * @return list<array{name: string, label: string}>
     */
    public function variables(string $default): array
    {
        return array_map(static function (string $variable): array {
            $label = __('notification_texts.tokens.'.$variable);

            return ['name' => $variable, 'label' => $label === 'notification_texts.tokens.'.$variable ? $variable : (string) $label];
        }, NotificationTextCatalog::variabili($default));
    }

    // ----------------------------------------------------------------- posta

    /**
     * La configurazione della posta senza la password, con il riquadro in
     * cima alla pagina del pannello («da dove sta uscendo la posta adesso»).
     *
     * @return array<string, mixed>
     */
    public function mail(User $user): array
    {
        $settings = app(MailSettings::class);
        $active = $settings->active();

        return [
            'enabled' => $settings->enabled,
            'active' => $active,
            'host' => $settings->host,
            'port' => $settings->port,
            'encryption' => $settings->encryption,
            'encryption_label' => match ($settings->encryption) {
                'tls' => 'TLS / STARTTLS',
                'ssl' => 'SSL',
                default => (string) __('mail_settings.fields.encryption_none'),
            },
            'username' => $settings->username,
            'has_password' => filled($settings->password),
            'from_address' => $settings->from_address,
            'from_name' => $settings->from_name,
            'verified' => $settings->verified(),
            'verified_at' => $settings->verified_at !== null ? Carbon::parse($settings->verified_at)->toIso8601String() : null,
            /* Una prova c'è stata, ma riguardava una configurazione diversa da quella salvata. */
            'stale_check' => $settings->verified_at !== null && ! $settings->verified(),
            'status' => [
                'custom' => $active,
                'title' => (string) __($active ? 'mail_settings.status.custom' : 'mail_settings.status.env'),
                'detail' => $active
                    ? (string) __('mail_settings.status.custom_detail', ['host' => $settings->host, 'porta' => $settings->port])
                    : (string) __('mail_settings.status.env_detail', ['trasporto' => config('mail.default'), 'mittente' => config('mail.from.address')]),
            ],
            /* La prova va all'indirizzo di chi la chiede, come nel pannello. */
            'recipient' => $user->email,
        ];
    }

    // ------------------------------------------------------------ newsletter

    /** @return array<string, mixed> */
    public function newsletter(): array
    {
        $values = NewsletterConfiguration::values();
        $defaults = NotificationTextCatalog::predefiniti();
        $texts = [];
        foreach (NewsletterConfiguration::TEXTS as $field => $key) {
            $texts[$field] = [
                'value' => (string) $values[$field],
                'default' => $defaults[$key],
                'max' => NewsletterConfiguration::TEXT_MAX[$field],
                'variables' => $this->variables($defaults[$key]),
            ];
        }

        return [
            'enabled' => (bool) $values['enabled'],
            'weekday' => (int) $values['weekday'],
            'time' => (string) $values['time'],
            'max_items' => (int) $values['max_items'],
            'texts' => $texts,
            'weekdays' => array_map(static fn (int $day, string $label): array => ['value' => $day, 'label' => $label], array_keys(NewsletterConfiguration::WEEKDAYS), NewsletterConfiguration::WEEKDAYS),
            'limits' => ['max_items_min' => NewsletterConfiguration::MAX_ITEMS_MIN, 'max_items_max' => NewsletterConfiguration::MAX_ITEMS_MAX],
        ];
    }

    // ----------------------------------------------------------------- cache

    /**
     * Le impostazioni della cache e l'ambiente del server, senza la password
     * di Redis (il pannello non la rimette nemmeno nel modulo).
     *
     * @return array<string, mixed>
     */
    public function cache(?string $serverSoftware): array
    {
        $settings = app(FrontendCacheSettings::class);

        return [
            'managed' => $settings->managed,
            'enabled' => $settings->enabled,
            'store' => $settings->store,
            'store_label' => (string) __($settings->store === 'frontend-redis' ? 'cache_settings.redis' : 'cache_settings.file'),
            'ttl_minutes' => $settings->ttl_minutes,
            'redis' => [
                'host' => $settings->redis_host,
                'port' => $settings->redis_port,
                'database' => $settings->redis_database,
                'username' => $settings->redis_username,
                'tls' => $settings->redis_tls,
                'has_password' => filled($settings->redis_password),
            ],
            'environment' => [
                'redis_extension' => extension_loaded('redis'),
                'litespeed' => str_contains(strtolower((string) $serverSoftware), 'litespeed'),
                'server' => str_contains(strtolower((string) $serverSoftware), 'litespeed') ? 'LiteSpeed' : (string) __('cache_settings.other_server'),
            ],
        ];
    }

    // ---------------------------------------------------------- consenso

    /** @return list<array{value: string, label: string, description: string}> */
    public function categories(): array
    {
        return array_map(static fn (ConsentCategory $category): array => [
            'value' => $category->value,
            'label' => $category->label(),
            'description' => $category->description(),
        ], ConsentCategory::cases());
    }

    /** @return array<string, mixed> */
    public function cookieRow(CookieDeclaration $cookie): array
    {
        return [
            'id' => $cookie->id,
            'category' => $cookie->category->value,
            'category_label' => $cookie->category->label(),
            'name' => $cookie->name,
            /* Vuoto vuol dire «questo sito», come la colonna del pannello. */
            'provider' => $cookie->getAttribute('provider'),
            'purpose' => $cookie->purpose,
            'duration' => $cookie->getAttribute('duration'),
            'sort_order' => (int) $cookie->getAttribute('sort_order'),
            /* Il pannello non ha «Modifica» né «Elimina» su questa tabella: si aggiunge e si riordina. */
            'abilities' => ['reorder' => true],
        ];
    }

    /** @return array<string, mixed> */
    public function scriptRow(ConsentScript $script, bool $withCode = false): array
    {
        /** @var ConsentCategory $category */
        $category = $script->getAttribute('category');
        $code = $script->getAttribute('code');
        $row = [
            'id' => $script->getKey(),
            'name' => $script->getAttribute('name'),
            'category' => $category->value,
            'category_label' => $category->label(),
            'enabled' => (bool) $script->getAttribute('enabled'),
            'sort_order' => (int) $script->getAttribute('sort_order'),
            'src' => $script->getAttribute('src'),
            'has_code' => is_string($code) && $code !== '',
            /* Modificare, accendere ed eliminare uno script si fa solo dal sito (decisione 5). */
            'abilities' => (object) [],
        ];
        if ($withCode) {
            $row['code'] = is_string($code) ? $code : null;
        }

        return $row;
    }

    // ------------------------------------------------------------- redirect

    /** @return array<string, mixed> */
    public function redirectRow(Redirect $redirect): array
    {
        $lastHit = $redirect->getAttribute('last_hit_at');

        return [
            'id' => $redirect->id,
            'from_path' => $redirect->from_path,
            'to_path' => $redirect->to_path,
            'city' => $redirect->city !== null ? ['id' => $redirect->city->id, 'name' => $redirect->city->name] : null,
            'status' => $redirect->status,
            'status_label' => $this->redirectStatusLabel($redirect->status),
            'is_wildcard' => $redirect->is_wildcard,
            'hits' => $redirect->hits,
            'last_hit_at' => $lastHit instanceof Carbon ? $lastHit->toIso8601String() : null,
            /* Il pannello aggiunge soltanto: nessuna azione sulla riga. */
            'abilities' => (object) [],
        ];
    }

    public function redirectStatusLabel(int $status): string
    {
        return (string) __($status === 302 ? 'redirects.status.temporary' : 'redirects.status.permanent');
    }
}
