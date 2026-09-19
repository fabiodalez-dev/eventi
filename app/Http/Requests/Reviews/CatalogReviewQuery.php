<?php

declare(strict_types=1);

namespace App\Http\Requests\Reviews;

use Illuminate\Foundation\Http\FormRequest;

class CatalogReviewQuery extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['page' => ['nullable', 'integer', 'min:1', 'max:10000']];
    }
}
