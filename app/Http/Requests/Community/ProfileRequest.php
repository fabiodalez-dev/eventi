<?php

declare(strict_types=1);

namespace App\Http\Requests\Community;

use App\Enums\ProfileVisibility;
use App\Models\CommunityProfile;
use App\Services\Community\AvatarImage;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

final class ProfileRequest extends FormRequest
{
    private const MAX_SIDE = 4096;

    /** La foto già ricodificata dal controllo dopo le regole: il controller la salva così com'è. */
    private ?string $avatarJpeg = null;

    protected function prepareForValidation(): void
    {
        /* Il nome utente è sempre minuscolo: «Admin_Recon» scritto da un
           telefono che mette la maiuscola da solo è lo stesso nome di
           «admin_recon», non un errore da correggere. Vale per il sito e per
           l'app, che passano entrambi di qui. */
        if (is_string($this->input('handle'))) {
            $this->merge(['handle' => mb_strtolower(trim($this->input('handle')))]);
        }
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
            Rule::unique('community_profiles', 'handle')->ignore($ignoreProfileId),
            Rule::unique('community_handle_aliases', 'handle')->where(fn ($query) => $query->where('community_profile_id', '!=', $ignoreProfileId ?? 0))];
    }

    /**
     * L'attesa fra due cambi di nome utente, come regola del campo.
     *
     * Sta sul campo `handle` perché così l'errore arriva dove arrivano gli altri:
     * sotto il campo sul sito, in `error.fields.handle` nell'API, che l'app mostra
     * già come messaggio della richiesta. Rimettere il nome che si ha non è un
     * cambio e passa sempre; il primo nome (nessun profilo) nemmeno. La usa anche
     * il controllo dal vivo, per dire prima del salvataggio che il cambio non è
     * ancora possibile.
     */
    public static function handleCooldownRule(?CommunityProfile $profile): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($profile): void {
            if (! $profile instanceof CommunityProfile || ! is_string($value) || mb_strtolower(trim($value)) === $profile->handle) {
                return;
            }

            $attesa = $profile->handleCooldownMessage();

            if ($attesa !== null) {
                $fail($attesa);
            }
        };
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $profile = $this->user()?->communityProfile;

        return [
            'handle' => ['required', self::handleCooldownRule($profile), ...self::handleRules($profile?->id)],
            'display_name' => ['required', 'string', 'max:80'], 'bio' => ['nullable', 'string', 'max:500'],
            'city_id' => ['nullable', 'integer', Rule::exists('cities', 'id')->where('is_active', true)],
            'visibility' => ['required', Rule::enum(ProfileVisibility::class)], 'indexable' => ['boolean'],
            'venue_ids' => ['sometimes', 'array', 'max:20'], 'venue_ids.*' => ['integer', 'distinct'],
            /* `bail`: un file che non è un'immagine fallirebbe insieme `image`,
               `mimes` e `dimensions`, e la stessa cosa detta tre volte. */
            'avatar' => ['nullable', 'bail', 'file', self::heicRule(), 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', self::sizeRule()],
            'remove_avatar' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return __('community.attributes');
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'avatar.uploaded' => __('community.avatar_errors.uploaded'),
            'avatar.file' => __('community.avatar_errors.uploaded'),
            'avatar.image' => __('community.avatar_errors.format'),
            'avatar.mimes' => __('community.avatar_errors.format'),
            'avatar.max' => __('community.avatar_errors.too_heavy', ['max' => 2]),
            'handle.regex' => __('community.handle_format'),
            'handle.unique' => __('community.handle_taken'),
        ];
    }

    /**
     * Le foto HEIC dell'iPhone hanno una frase loro: «deve essere un'immagine»
     * detto di una foto appena scattata non si capisce.
     */
    private static function heicRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof UploadedFile) {
                return;
            }
            $mime = strtolower((string) $value->getMimeType());
            $extension = strtolower($value->getClientOriginalExtension());
            if (in_array($mime, ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'], true) || in_array($extension, ['heic', 'heif'], true)) {
                $fail(__('community.avatar_errors.heic'));
            }
        };
    }

    /**
     * Al massimo 4096 px per lato: la foto si decodifica in memoria, e un'immagine
     * enorme (anche leggera, se compressa bene) la esaurirebbe. Diversamente dalla
     * regola `dimensions`, un file di cui non si leggono le misure non riceve qui
     * «troppo grande»: lo giudica la decodifica, che dice che non si legge.
     */
    private static function sizeRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            $size = $value instanceof UploadedFile ? @getimagesize($value->getRealPath()) : false;
            if (is_array($size) && max((int) $size[0], (int) $size[1]) > self::MAX_SIDE) {
                $fail(__('community.avatar_errors.too_big', ['max' => self::MAX_SIDE]));
            }
        };
    }

    /**
     * Dopo le regole, la foto si decodifica davvero: un file che passa il
     * controllo del tipo ma non si legge (troncato, animato, rovinato) dà un
     * errore sul campo come gli altri, prima che il profilo venga salvato.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $file = $this->file('avatar');
            if (! $file instanceof UploadedFile || $validator->errors()->has('avatar')) {
                return;
            }
            try {
                $this->avatarJpeg = app(AvatarImage::class)->encode($file->getRealPath());
            } catch (InvalidArgumentException $errore) {
                $validator->errors()->add('avatar', $errore->getMessage());
            }
        }];
    }

    /** La foto pronta da salvare, o null se non ne è arrivata una. */
    public function avatarJpeg(): ?string
    {
        return $this->avatarJpeg;
    }
}
