<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\ReportReason;
use App\Enums\ReportSubject;
use Illuminate\Validation\Rule;

/**
 * `POST /v1/reports` (§13.1, §14.6).
 *
 * La segnalazione dice **su che cosa** verte: un evento o un locale, indicati
 * con l'alias della morph map (`event`, `venue`) e lo slug pubblico. Gli id
 * interni non entrano nel contratto — sono un dettaglio del database — e lo
 * slug è ciò che il client ha già in mano, perché è quello che ha ricevuto
 * nella risposta precedente.
 */
final class StoreReportRequest extends ApiRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'subject_type' => ['required', Rule::in(ReportSubject::values())],
            'subject_slug' => ['required', 'string', 'max:255'],
            'reason' => ['required', Rule::in(ReportReason::values())],
            'note' => ['nullable', 'string', 'max:2000'],
            'reporter_email' => ['nullable', 'email:filter', 'max:255'],
        ];
    }

    public function reason(): ReportReason
    {
        return ReportReason::from((string) $this->validated('reason'));
    }

    public function subjectType(): ReportSubject
    {
        return ReportSubject::from((string) $this->validated('subject_type'));
    }

    public function subjectSlug(): string
    {
        return (string) $this->validated('subject_slug');
    }
}
