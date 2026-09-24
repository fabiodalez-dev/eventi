<?php

declare(strict_types=1);

namespace App\Http\Requests\Ticketing;

use App\Models\Booking;
use App\Models\EventOccurrence;
use Illuminate\Foundation\Http\FormRequest;

class StaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        $date = $this->route('occurrence');

        return $date instanceof EventOccurrence && $this->user()?->can('manage', [Booking::class, $date]) === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['email' => ['required', 'email', 'max:255', 'exists:users,email'], 'remove' => ['sometimes', 'boolean']];
    }
}
