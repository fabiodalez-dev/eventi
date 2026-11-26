<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Social;

use App\Services\Social\SocialSettingsDesk;

/**
 * Il salvataggio delle impostazioni social dall'app (R-SO-05): gli stessi
 * campi e le stesse regole del modulo della pagina, tranne i segreti (App
 * Secret, token della Pagina, token del bot), che dall'app non si scrivono
 * (decisione 5): mandarli è un errore sul campo, non un valore ignorato in
 * silenzio.
 */
final class SocialSettingsRequest extends EditorialSocialRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $rules = [
            'app_id' => ['nullable', 'string', 'regex:/^\d+$/', 'max:80'],
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'page_id' => ['nullable', 'string', 'regex:/^\d+$/', 'max:80'],
            'instagram_id' => ['nullable', 'string', 'regex:/^\d+$/', 'max:80'],
            'graph_version' => ['required', 'string', 'regex:/^v\d+\.\d+$/'],
            'facebook_enabled' => ['boolean'],
            'instagram_enabled' => ['boolean'],
            'telegram_chat_id' => ['nullable', 'string', 'regex:/^(@[A-Za-z][A-Za-z0-9_]{4,}|-?\d+)$/', 'max:100'],
            'telegram_enabled' => ['boolean'],
            'automatic' => ['boolean'],
            'graphic_options' => ['required', 'array'],
            'graphic_options.monochrome' => ['boolean'],
            'graphic_options.show_address' => ['boolean'],
            'graphic_options.show_price' => ['boolean'],
            'graphic_options.image_fit' => ['required', 'string', 'in:contain,cover'],
            'publish_time' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'caption' => ['required', 'string', 'max:2200'],
        ];
        foreach (SocialSettingsDesk::SECRETS as $secret) {
            $rules[$secret] = ['prohibited'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return array_fill_keys(array_map(fn (string $secret): string => $secret.'.prohibited', SocialSettingsDesk::SECRETS), (string) __('pannelli-social.app.secret_web_only'));
    }

    /**
     * I valori da salvare, nella forma del modulo della pagina: i sì/no
     * assenti valgono no, come un interruttore spento.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $values = $this->safe()->except(SocialSettingsDesk::SECRETS);
        foreach (['facebook_enabled', 'instagram_enabled', 'telegram_enabled', 'automatic'] as $flag) {
            $values[$flag] = $this->boolean($flag);
        }
        $options = is_array($values['graphic_options'] ?? null) ? $values['graphic_options'] : [];
        $values['graphic_options'] = [
            'monochrome' => (bool) ($options['monochrome'] ?? false),
            'show_address' => (bool) ($options['show_address'] ?? false),
            'show_price' => (bool) ($options['show_price'] ?? false),
            'image_fit' => (string) ($options['image_fit'] ?? 'contain'),
        ];
        foreach (['app_id', 'page_id', 'instagram_id', 'telegram_chat_id'] as $nullable) {
            $values[$nullable] = isset($values[$nullable]) && $values[$nullable] !== '' ? (string) $values[$nullable] : null;
        }

        return $values;
    }
}
