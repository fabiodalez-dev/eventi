<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RememberLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['observed_at' => ['nullable', 'integer', 'max:'.now()->timestamp, 'min:'.now()->subMonthsNoOverflow(6)->timestamp],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'], 'remember' => ['required', 'accepted']];
    }
}
