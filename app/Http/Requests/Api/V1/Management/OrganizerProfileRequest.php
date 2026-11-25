<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management;

use App\Actions\UpdateOrganizerProfile;
use App\Enums\ContactMode;
use App\Models\Organizer;
use App\Support\Management\ManagementSubject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Il profilo dell'organizzazione dall'app: `GET` e `PATCH O/profile`. Solo chi
 * l'ha creata (`OrganizerPolicy::updateProfile`), come la pagina «Profilo»; i
 * membri ricevono 403. Il `PATCH` convalida solo le chiavi inviate.
 */
final class OrganizerProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updateProfile', $this->organizer()) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if (! $this->isMethod('patch')) {
            return [];
        }

        return [
            'description' => ['sometimes', 'nullable', 'string', 'max:'.UpdateOrganizerProfile::DESCRIPTION_MAX],
            'website' => ['sometimes', 'nullable', 'string', 'url', 'max:2048'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'contact_mode' => ['sometimes', 'required', Rule::enum(ContactMode::class)],
            'contact_email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'description' => __('panel.organizer_profile.description'),
            'website' => __('panel.organizer_profile.website'),
            'email' => __('panel.organizer_profile.email'),
            'contact_mode' => __('contact.mode_question'),
            'contact_email' => __('contact.recipient'),
        ];
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->isMethod('patch')) {
                return;
            }
            $mode = $this->has('contact_mode') ? $this->input('contact_mode') : $this->organizer()->contact_mode->value;
            $email = $this->has('contact_email') ? $this->input('contact_email') : $this->organizer()->contact_email;
            if (in_array($mode, [ContactMode::Members->value, ContactMode::Everyone->value], true) && blank($email)) {
                $validator->errors()->add('contact_email', __('validation.required', ['attribute' => __('contact.recipient')]));
            }
        }];
    }

    /** @return array<string, mixed> */
    public function profileState(): array
    {
        return array_intersect_key($this->validated(), array_flip(UpdateOrganizerProfile::FIELDS));
    }

    public function organizer(): Organizer
    {
        $organizer = ManagementSubject::require()->organizer();
        abort_unless($organizer instanceof Organizer, 404);

        return $organizer;
    }
}
