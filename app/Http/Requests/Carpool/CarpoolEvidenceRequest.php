<?php

declare(strict_types=1);

namespace App\Http\Requests\Carpool;

use App\Enums\Permission;
use App\Services\Carpool\CommunitySafety;
use Illuminate\Foundation\Http\FormRequest;

class CarpoolEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && app(CommunitySafety::class)->staff($this->user(), Permission::ReadCommunityMessages, true);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:1000'], 'password' => ['required', 'current_password:web'], 'export' => ['sometimes', 'boolean']];
    }
}
