<?php

declare(strict_types=1);

namespace App\Http\Requests\Ticketing;

class CheckInRequest extends ManageRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'size:64', 'alpha_num:ascii']];
    }
}
