<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Models\City;
use App\Models\SocialConnection;
use App\Support\Social\SocialErrorMessage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Le impostazioni dei social della redazione (R-SO-05…07): il collegamento
 * Meta (Facebook e Instagram), il canale Telegram, la pubblicazione
 * automatica con lo stile della grafica, l'ora e il testo dei post.
 *
 * Stava dentro la pagina «Account e pubblicazione social» del pannello
 * (`SocialSettings`); adesso la chiamano la pagina e l'API della redazione
 * nell'app. Il collegamento è uno solo (`SocialConnection::first()`), come
 * nella pagina.
 *
 * I segreti (App Secret, token della Pagina, token del bot) non escono mai
 * in lettura: `SocialConnection` li nasconde, e qui si dice solo se ci sono.
 * Un segreto lasciato vuoto nel salvataggio resta quello salvato. L'app non
 * li scrive affatto (decisione 5 del piano della redazione): li manda solo
 * la pagina del sito.
 */
final class SocialSettingsDesk
{
    /** I segreti del collegamento, scritti solo dalla pagina del sito. */
    public const SECRETS = ['access_token', 'app_secret', 'telegram_bot_token'];

    /** Le opzioni della grafica dei post automatici quando non sono mai state salvate. */
    public const GRAPHIC_DEFAULTS = ['monochrome' => true, 'show_address' => true, 'show_price' => true, 'image_fit' => 'contain'];

    public function __construct(
        private readonly SocialPublisher $publisher,
        private readonly TelegramPublisher $telegram,
    ) {}

    /**
     * I valori del modulo, come li apre la pagina: quelli salvati (senza i
     * segreti), oppure i valori di partenza.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $connection = SocialConnection::first();
        $values = $connection?->toArray() ?? ['city_id' => City::first()?->id, 'graph_version' => 'v25.0', 'publish_time' => '09:00', 'caption' => __('social.default_template')];
        $values['graphic_options'] = array_merge(self::GRAPHIC_DEFAULTS, $values['graphic_options'] ?? []);
        unset($values['id'], $values['verified_at'], $values['created_at'], $values['updated_at']);

        return $values;
    }

    /**
     * Salva. I segnaposti del testo devono essere quelli conosciuti (errore
     * su `$captionKey`); un segreto vuoto non cambia quello salvato; un
     * cambio dei dati Meta o Telegram toglie la verifica; la pubblicazione
     * automatica si spegne se nessun canale è verificato.
     *
     * @param  array<string, mixed>  $values
     * @return array{connection: SocialConnection, automatic_off: bool}
     */
    public function save(array $values, string $captionKey = 'caption'): array
    {
        preg_match_all('/(?<!\w):([a-z_]+)/u', (string) ($values['caption'] ?? ''), $matches);
        $unknown = array_diff($matches[1], SocialPublisher::TOKENS);
        if ($unknown !== []) {
            throw ValidationException::withMessages([$captionKey => __('social.invalid_tokens', ['tokens' => implode(', ', $unknown)])]);
        }
        $connection = SocialConnection::first() ?? new SocialConnection;
        foreach (self::SECRETS as $secret) {
            if (blank($values[$secret] ?? null)) {
                unset($values[$secret]);
            }
        }
        $connection->fill($values);
        if ($connection->isDirty(['city_id', 'app_id', 'app_secret', 'access_token', 'page_id', 'instagram_id', 'graph_version', 'facebook_enabled', 'instagram_enabled'])) {
            $connection->verified_at = null;
        }
        if ($connection->isDirty(['city_id', 'telegram_bot_token', 'telegram_chat_id', 'telegram_enabled'])) {
            $connection->telegram_verified_at = null;
        }
        $off = false;
        if ($connection->automatic && ! (($connection->verified_at && ($connection->instagram_enabled || $connection->facebook_enabled)) || ($connection->telegram_enabled && $connection->telegram_verified_at))) {
            $connection->automatic = false;
            $off = true;
        }
        $connection->save();

        return ['connection' => $connection, 'automatic_off' => $off];
    }

    /** «Scollega Meta»: via il token della Pagina, la verifica e la pubblicazione automatica. */
    public function disconnectMeta(): void
    {
        SocialConnection::query()->update(['access_token' => null, 'verified_at' => null, 'automatic' => false]);
    }

    /**
     * «Verifica collegamento» di Meta, sui valori salvati: serve un token e
     * almeno uno fra Facebook e Instagram. Qualunque errore vale «non
     * verificato», come nella pagina (il messaggio non dice di più).
     */
    public function verifyMeta(): bool
    {
        try {
            $connection = SocialConnection::firstOrFail();
            abort_unless($connection->access_token && ($connection->facebook_enabled || $connection->instagram_enabled), 422);
            $this->publisher->verify($connection);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * «Verifica Telegram»: bot, canale e permesso di pubblicare, senza
     * mandare messaggi. `null` se va, altrimenti il motivo da mostrare.
     */
    public function verifyTelegram(): ?string
    {
        try {
            $this->telegram->verify(SocialConnection::firstOrFail());

            return null;
        } catch (RuntimeException $e) {
            /* Un errore del database o il collegamento che manca non diventano la frase da mostrare. */
            $message = SocialErrorMessage::forPeople($e);
            if ($message === null) {
                report($e);
            }

            return $message ?? __('social.internal_error');
        }
    }

    /**
     * Lo stato del collegamento come lo scrivono i due riquadri in cima alla
     * pagina: quali segreti ci sono (sì o no, mai il valore) e quali canali
     * sono verificati.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $connection = SocialConnection::first();

        return [
            'exists' => $connection !== null,
            'app_secret_saved' => filled($connection?->app_secret),
            'access_token_saved' => filled($connection?->access_token),
            'telegram_bot_token_saved' => filled($connection?->telegram_bot_token),
            'meta_verified_at' => $connection?->verified_at?->copy()->utc()->toIso8601String(),
            'telegram_verified_at' => $connection?->telegram_verified_at?->copy()->utc()->toIso8601String(),
        ];
    }
}
