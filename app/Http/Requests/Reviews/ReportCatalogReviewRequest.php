<?php

declare(strict_types=1);

namespace App\Http\Requests\Reviews;

use Illuminate\Foundation\Http\FormRequest;

class ReportCatalogReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canParticipateInCommunity() ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'min:10', 'max:3000']];
    }
}
