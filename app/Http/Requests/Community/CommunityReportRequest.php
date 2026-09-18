<?php

declare(strict_types=1);

namespace App\Http\Requests\Community;

use App\Enums\ReportReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CommunityReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['type' => ['required', Rule::in(['community_profile', 'community_post', 'community_comment'])],
            'id' => ['required', 'integer', 'min:1'], 'reason' => ['required', Rule::enum(ReportReason::class)],
            'note' => ['nullable', 'string', 'max:1000']];
    }
}
