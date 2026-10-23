<?php

declare(strict_types=1);

namespace App\Http\Requests\Ticketing;

class MessageAttendeesRequest extends ManageRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:160', 'regex:/\S/u'],
            'body' => ['required', 'string', 'max:2000', 'regex:/\S/u'],
            'include_waitlisted' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['subject' => __('ticketing.messages.subject'), 'body' => __('ticketing.messages.body')];
    }
}
