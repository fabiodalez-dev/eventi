<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Users;

/** Le scelte e i messaggi comuni ai moduli degli account della redazione. */
final class UserFormRules
{
    /** Le lingue del modulo del pannello. */
    public const LOCALES = ['it', 'en', 'de'];

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'roles.*.in' => __('users.app.role_web_only'),
            'email.prohibited' => __('users.app.credentials_web_only'),
            'password.prohibited' => __('users.app.credentials_web_only'),
        ];
    }
}
