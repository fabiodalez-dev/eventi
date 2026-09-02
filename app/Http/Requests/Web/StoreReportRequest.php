<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Enums\ReportReason;
use App\Support\Honeypot;
use App\Support\Turnstile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Segnalazione di un errore su una scheda pubblica (§11.5, §14.6).
 *
 * Il motivo è obbligatorio e l'email no: chi segnala un orario sbagliato sta
 * facendo un favore, non aprendo una pratica.
 */
class StoreReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::in(ReportReason::values())],
            'note' => ['nullable', 'string', 'max:2000'],
            'reporter_email' => ['nullable', 'email:filter', 'max:255'],
            ...Honeypot::rules(),
            ...Turnstile::rules('segnalazione'),
        ];
    }

    public function reason(): ReportReason
    {
        return ReportReason::from($this->string('reason')->value());
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        /** @var array<string, string> $fields */
        $fields = trans('forms.report.fields');

        return $fields;
    }
}
