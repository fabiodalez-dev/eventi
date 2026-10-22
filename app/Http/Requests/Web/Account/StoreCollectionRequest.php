<?php

declare(strict_types=1);

namespace App\Http\Requests\Web\Account;

use Illuminate\Foundation\Http\FormRequest;

class StoreCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', 'regex:/\S/u'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_public' => ['sometimes', 'boolean'],
            'occurrence_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => __('collections.fields.name'), 'description' => __('collections.fields.description')];
    }
}
