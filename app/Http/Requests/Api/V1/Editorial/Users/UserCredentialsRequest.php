<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Users;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Email e password dalla scheda (R-US-05), dall'app **solo le proprie**: per
 * un'altra persona restano sul sito (decisione 4). Le regole sono quelle del
 * modulo del pannello; la password vuota resta quella di prima.
 */
final class UserCredentialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');
        $actor = $this->user();

        return $target instanceof User && $actor instanceof User && $actor->is($target) && $actor->can('update', $target);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $target = $this->route('user');

        return [
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($target instanceof User ? $target->id : null)],
            'password' => ['nullable', 'string', Password::defaults()],
        ];
    }
}
