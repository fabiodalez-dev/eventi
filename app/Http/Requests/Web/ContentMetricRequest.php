<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Enums\ContentMetric;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContentMetricRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['metric' => ['required', Rule::enum(ContentMetric::class)]];
    }
}
