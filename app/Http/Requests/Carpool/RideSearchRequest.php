<?php

declare(strict_types=1);

namespace App\Http\Requests\Carpool;

use App\Enums\RideAccessibility;
use App\Enums\RideFeedbackKind;
use App\Enums\RideLeg;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RideSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['request_key' => ['required', 'uuid']] + match ((string) $this->route('action')) {
            'search' => ['occurrence_id' => ['required', 'integer', 'min:1'], 'leg' => ['required', Rule::enum(RideLeg::class)],
                'zone' => ['nullable', 'string', 'max:120'], 'seats' => ['required', 'integer', 'min:1', 'max:8'],
                'accessibility' => ['required', Rule::enum(RideAccessibility::class)], 'earliest_at' => ['required', 'date'],
                'latest_at' => ['required', 'date', 'after:earliest_at'], 'is_public' => ['required', 'boolean'], 'alerts_enabled' => ['required', 'boolean']],
            'toggle-search' => ['search_id' => ['required', 'integer', 'min:1'], 'active' => ['required', 'boolean']],
            'suggest' => ['search_id' => ['required', 'integer', 'min:1'], 'offer_id' => ['required', 'integer', 'min:1']],
            'template' => ['offer_id' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:80']],
            'delete-template' => ['template_id' => ['required', 'integer', 'min:1']],
            'feedback' => ['request_id' => ['required', 'integer', 'min:1'], 'kind' => ['required', Rule::enum(RideFeedbackKind::class)], 'body' => ['nullable', 'string', 'max:1000']],
            default => ['action' => ['required', Rule::in([])]],
        };
    }
}
