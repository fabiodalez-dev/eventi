<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Settings;

use Illuminate\Foundation\Http\FormRequest;

/** L'esempio di un testo delle email mentre lo si scrive: la chiave e il testo. */
final class PreviewNotificationTextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:191'],
            'value' => ['nullable', 'string', 'max:'.UpdateNotificationTextRequest::VALUE_MAX],
        ];
    }
}
