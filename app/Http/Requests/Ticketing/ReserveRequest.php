<?php

declare(strict_types=1);

namespace App\Http\Requests\Ticketing;

use Illuminate\Foundation\Http\FormRequest;

class ReserveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['attendees' => __('ticketing.participants'), 'attendees.*' => __('ticketing.name'), 'attendees.*.first_name' => __('ticketing.fields.first_name'), 'attendees.*.last_name' => __('ticketing.fields.last_name'), 'booker' => __('ticketing.booker'), 'accept_terms' => __('ticketing.consent'), 'request_key' => __('ticketing.request_key'), 'waitlist' => __('ticketing.waitlist')];
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $rules = [
            'attendees' => ['required', 'array', 'list', 'min:1', 'max:20'],
            'attendees.*' => ['required'],
            'booker' => ['sometimes', 'array:first_name,last_name,phone,address,city,postal_code,country'],
            'request_key' => ['required', 'uuid'],
            'waitlist' => ['sometimes', 'boolean'],
            'accept_terms' => ['required', 'accepted'],
        ];
        foreach (array_slice((array) $this->input('attendees', []), 0, 20, true) as $index => $attendee) {
            if (is_array($attendee)) {
                $rules['booker'] = ['required', 'array:first_name,last_name,phone,address,city,postal_code,country', 'min:2'];
                $rules['attendees.'.$index] = ['required', 'array:first_name,last_name'];
                foreach (['first_name', 'last_name'] as $field) {
                    $rules['attendees.'.$index.'.'.$field] = ['required', 'string', 'max:120', 'regex:/\S/u'];
                }
            } else {
                $rules['attendees.'.$index] = ['required', 'string', 'max:120', 'regex:/\S/u'];
            }
        }

        return $rules;
    }
}
