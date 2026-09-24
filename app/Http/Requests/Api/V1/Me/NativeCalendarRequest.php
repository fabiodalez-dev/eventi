<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Me;

use App\Http\Requests\Web\EventFilterRequest;

class NativeCalendarRequest extends EventFilterRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'saved_only' => ['sometimes', 'boolean']];
    }
}
