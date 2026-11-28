<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Editorial\Social\EditorialSocialRequest;
use App\Http\Requests\Api\V1\Editorial\Social\SocialSettingsRequest;
use App\Models\City;
use App\Models\SocialConnection;
use App\Services\Social\MetaOAuth;
use App\Services\Social\SocialPublisher;
use App\Services\Social\SocialSettingsDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * «Account e pubblicazione social» della redazione nell'app (R-SO-05…07,
 * R-WB-04), come la pagina `SocialSettings` del pannello e con la stessa
 * classe (`SocialSettingsDesk`).
 *
 * Decisione 5 del piano: nell'app si leggono e si cambiano i campi che non
 * sono segreti (App ID, città, Pagina, Instagram, versione, canali,
 * Telegram, automatico, grafica, orario, testo) e si fanno le verifiche; App
 * Secret, token della Pagina e token del bot si scrivono solo dal sito, e
 * in lettura si dice solo se ci sono. Decisione 14: il collegamento con Meta
 * (OAuth) si fa nel browser, l'app riceve l'indirizzo da aprire.
 */
final class SocialSettingsController extends Controller
{
    public function __construct(private readonly SocialSettingsDesk $settings) {}

    /** I valori del modulo, lo stato dei segreti e delle verifiche, le città e i segnaposti del testo. */
    public function show(EditorialSocialRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->payload()]);
    }

    /** «Salva impostazioni». Con l'automatico acceso e nessun canale verificato, l'automatico si spegne (`meta.warning`). */
    public function update(SocialSettingsRequest $request): JsonResponse
    {
        $saved = $this->settings->save($request->values());

        return response()->json([
            'data' => $this->payload(),
            'meta' => [
                'message' => __('social.saved'),
                'warning' => $saved['automatic_off'] ? __('social.not_connected') : null,
            ],
        ]);
    }

    /** «Verifica collegamento» di Meta sui valori salvati (l'app salva prima, come la pagina). */
    public function verify(EditorialSocialRequest $request): JsonResponse
    {
        if (! $this->settings->verifyMeta()) {
            throw ValidationException::withMessages(['verify' => __('social.verify_failed')]);
        }

        return response()->json(['data' => $this->payload(), 'meta' => ['message' => __('social.verified')]]);
    }

    /** «Verifica Telegram»: bot, canale e permesso di pubblicare, senza mandare messaggi. */
    public function verifyTelegram(EditorialSocialRequest $request): JsonResponse
    {
        $error = $this->settings->verifyTelegram();
        if ($error !== null) {
            throw ValidationException::withMessages(['telegram' => $error]);
        }

        return response()->json(['data' => $this->payload(), 'meta' => ['message' => __('pannelli-social.app.verified_telegram')]]);
    }

    /**
     * «Collega con Meta»: l'indirizzo del sito che avvia l'autorizzazione
     * (`social.meta.connect`), da aprire nel browser con l'accesso al sito.
     * Senza App ID e App Secret salvati è 422, come il ritorno con il
     * messaggio della pagina.
     */
    public function connect(EditorialSocialRequest $request, MetaOAuth $oauth): JsonResponse
    {
        $connection = SocialConnection::query()->first();
        try {
            abort_if($connection === null, 422);
            $oauth->assertReady($connection);
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['meta' => __('pannelli-social.app.meta_not_ready')]);
        }

        return response()->json(['data' => [
            'url' => route('social.meta.connect'),
            'callback_url' => route('social.meta.callback'),
            'return_path' => 'admin/social-settings',
        ]]);
    }

    /** «Scollega Meta»: via il token della Pagina, la verifica e l'automatico. */
    public function disconnect(EditorialSocialRequest $request): JsonResponse
    {
        $this->settings->disconnectMeta();

        return response()->json(['data' => $this->payload(), 'meta' => ['message' => __('pannelli-social.app.disconnected')]]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $values = $this->settings->values();
        $options = is_array($values['graphic_options'] ?? null) ? $values['graphic_options'] : SocialSettingsDesk::GRAPHIC_DEFAULTS;

        return [
            'values' => [
                'app_id' => self::text($values['app_id'] ?? null),
                'city_id' => isset($values['city_id']) ? (int) $values['city_id'] : null,
                'page_id' => self::text($values['page_id'] ?? null),
                'instagram_id' => self::text($values['instagram_id'] ?? null),
                'graph_version' => (string) ($values['graph_version'] ?? 'v25.0'),
                'facebook_enabled' => (bool) ($values['facebook_enabled'] ?? false),
                'instagram_enabled' => (bool) ($values['instagram_enabled'] ?? false),
                'telegram_chat_id' => self::text($values['telegram_chat_id'] ?? null),
                'telegram_enabled' => (bool) ($values['telegram_enabled'] ?? false),
                'automatic' => (bool) ($values['automatic'] ?? false),
                'graphic_options' => [
                    'monochrome' => (bool) ($options['monochrome'] ?? true),
                    'show_address' => (bool) ($options['show_address'] ?? true),
                    'show_price' => (bool) ($options['show_price'] ?? true),
                    'image_fit' => (string) ($options['image_fit'] ?? 'contain'),
                ],
                'publish_time' => substr((string) ($values['publish_time'] ?? '09:00'), 0, 5),
                'caption' => (string) ($values['caption'] ?? ''),
            ],
            'status' => $this->settings->status(),
            'cities' => City::query()->orderBy('id')->get(['id', 'name'])
                ->map(fn (City $city): array => ['id' => (int) $city->getKey(), 'name' => (string) $city->name])->values()->all(),
            'tokens' => array_map(fn (string $token): array => ['token' => ':'.$token, 'label' => (string) __('social.tokens.'.$token)], SocialPublisher::TOKENS),
            'callback_url' => route('social.meta.callback'),
            'limits' => ['caption_max' => 2200, 'telegram_caption_max' => 1024],
            'web_path' => 'admin/social-settings',
        ];
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
