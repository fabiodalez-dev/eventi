<?php

declare(strict_types=1);

namespace App\Http\Requests\Ticketing;

class CancelRequest extends ManageRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['ticket_id' => ['nullable', 'integer', 'min:1'], 'reason' => ['nullable', 'string', 'max:500']];
    }
}
