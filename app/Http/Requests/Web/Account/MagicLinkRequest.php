<?php

declare(strict_types=1);

namespace App\Http\Requests\Web\Account;

use Illuminate\Foundation\Http\FormRequest;

class MagicLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:filter', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['email' => __('account.login.email')];
    }
}
