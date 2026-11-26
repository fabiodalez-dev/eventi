<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Editorial\Moderation;

use App\Enums\ReportStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * L'esito di una segnalazione: i due campi del modulo «Modifica» del
 * pannello, stato (obbligatorio) e nota di risoluzione (facoltativa, quanto
 * sta nella colonna `text`). Chi decide e quando li scrive `ResolveReport`.
 */
final class ResolveReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ReportStatus::class)],
            'resolution_note' => ['nullable', 'string', 'max:65535'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['status' => __('admin.fields.status'), 'resolution_note' => __('admin.fields.resolution_note')];
    }
}
