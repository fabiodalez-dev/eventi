<?php

declare(strict_types=1);

namespace App\Http\Requests\Community;

use App\Enums\ProfileVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ProfileRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('venue_ids')) {
            $this->merge(['venue_ids' => array_values(array_filter((array) $this->input('venue_ids'), fn ($id) => $id !== null && $id !== ''))]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Le regole del nome utente, da sole.
     *
     * Le usa anche il controllo dal vivo accanto al campo: se le riscrivesse per
     * conto proprio, prima o poi direbbe «disponibile» su un nome che poi il
     * salvataggio rifiuta — ed è la forma peggiore di errore, perché arriva dopo
     * che la persona ha già compilato il resto.
     *
     * @return list<mixed>
     */
    public static function handleRules(?int $ignoreProfileId): array
    {
        return ['string', 'min:3', 'max:40', 'regex:/^[a-z0-9][a-z0-9_]+$/',
            Rule::unique('community_profiles', 'handle')->ignore($ignoreProfileId)];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'handle' => ['required', ...self::handleRules($this->user()?->communityProfile?->id)],
            'display_name' => ['required', 'string', 'max:80'], 'bio' => ['nullable', 'string', 'max:500'],
            'city_id' => ['nullable', 'integer', Rule::exists('cities', 'id')->where('is_active', true)],
            'visibility' => ['required', Rule::enum(ProfileVisibility::class)], 'indexable' => ['boolean'],
            'venue_ids' => ['sometimes', 'array', 'max:20'], 'venue_ids.*' => ['integer', 'distinct'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096'],
            'remove_avatar' => ['sometimes', 'boolean'],
        ];
    }
}
