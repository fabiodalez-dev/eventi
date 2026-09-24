<?php

declare(strict_types=1);

namespace App\Http\Requests\Ticketing;

use App\Models\Booking;
use App\Models\EventOccurrence;
use Illuminate\Foundation\Http\FormRequest;

class DecisionDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $date = $this->route('occurrence');

        return $date instanceof EventOccurrence && $this->user()?->can('manage', [Booking::class, $date]) === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $rules = [
            'practical_details' => ['present', 'array:accessibility,membership,entrance_notes,membership_notes,parking_notes,transit_notes,food_notes,start_notes'],
            'practical_details.accessibility' => ['nullable', 'in:yes,no,unknown'],
            'practical_details.membership' => ['nullable', 'in:required,not_required'],
            'cost_breakdown' => ['present', 'array:admission,drink,membership,other'],
            'cost_breakdown.*' => ['nullable', 'numeric', 'min:0', 'max:100000', 'decimal:0,2'],
        ];
        foreach (['entrance_notes', 'membership_notes', 'parking_notes', 'transit_notes', 'food_notes', 'start_notes'] as $field) {
            $rules['practical_details.'.$field] = ['nullable', 'string', 'max:2000'];
        }

        return $rules;
    }
}
