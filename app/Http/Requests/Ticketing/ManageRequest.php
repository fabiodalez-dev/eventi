<?php

declare(strict_types=1);

namespace App\Http\Requests\Ticketing;

use App\Enums\BookingQuestionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ManageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return match ($this->route()?->getActionMethod()) {
            'configure' => [
                'booking_enabled' => ['required', 'boolean'],
                'booking_capacity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
                'booking_limit' => ['required', 'integer', 'min:1', 'max:20'],
                'booking_waitlist' => ['required', 'boolean'],
                'booking_opens_at' => ['nullable', 'date'],
                'booking_closes_at' => ['nullable', 'date', ...($this->filled('booking_opens_at') ? ['after:booking_opens_at'] : [])],
                'cancellation_closes_at' => ['nullable', 'date'],
                'booking_instructions' => ['nullable', 'string', 'max:3000'],
                'booking_fields' => ['sometimes', 'array:phone,address,city,postal_code,country'],
                'booking_fields.*' => ['required', 'string', 'in:hidden,optional,required'],
                'booking_questions' => ['sometimes', 'array', 'max:10'],
                'booking_questions.*' => ['array:id,label,type,options,required'],
                'booking_questions.*.id' => ['nullable', 'string', 'max:20'],
                'booking_questions.*.label' => ['nullable', 'string', 'max:160'],
                'booking_questions.*.type' => ['required_with:booking_questions.*.label', 'string', Rule::enum(BookingQuestionType::class)],
                'booking_questions.*.options' => ['nullable', 'string', 'max:1000'],
                'booking_questions.*.required' => ['sometimes', 'boolean'],
            ],
            default => ['q' => ['nullable', 'string', 'max:120'], 'page' => ['nullable', 'integer', 'min:1']],
        };
    }
}
