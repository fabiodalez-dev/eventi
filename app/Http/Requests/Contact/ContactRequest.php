<?php

declare(strict_types=1);

namespace App\Http\Requests\Contact;

use Illuminate\Foundation\Http\FormRequest;

class ContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:100', 'not_regex:/[\r\n]/'],
            'email' => ['required', 'email', 'max:255', 'not_regex:/[\r\n]/'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'g-recaptcha-response' => ['nullable', 'string', 'max:4096']];
    }

    protected function getRedirectUrl(): string
    {
        return route($this->route('type') === 'venues' ? 'venues.show' : 'organizers.show', ['slug' => $this->route('slug')]).'#contatta';
    }

    protected function prepareForValidation(): void
    {
        $user = $this->user() ?? $this->user('sanctum');
        if ($user !== null) {
            $this->merge(['name' => $user->name, 'email' => $user->email]);
        }
    }
}
