<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateReport;
use App\Enums\ApiErrorCode;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\ReportSubject;
use App\Enums\VenueStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\InteractsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreReportRequest;
use App\Models\Event;
use App\Models\Report;
use App\Models\Venue;
use App\Support\Api\ApiDate;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /v1/reports` (§13.1, §14.6).
 *
 * Un orario sbagliato lo vede prima chi ci va che chi lo ha scritto: la
 * segnalazione è aperta anche a chi non ha un account, e l'email è
 * facoltativa perché serve solo a poter rispondere.
 *
 * La stessa segnalazione mandata due volte è un **409**, non un secondo
 * record: due righe identiche in coda di moderazione fanno perdere tempo a
 * chi le legge, e un client che ritenta dopo un timeout non deve produrne una.
 */
final class ReportController extends Controller
{
    use InteractsWithApi;

    public function __invoke(StoreReportRequest $request, CreateReport $action): JsonResponse
    {
        $city = $this->city();
        $subject = $this->subject($request);
        $reason = $request->reason();

        $this->assertNotPending($request, $subject, $reason);

        $userId = $request->user('sanctum')?->getKey();
        $note = $request->validated('note');
        $email = $request->validated('reporter_email');

        $report = $action->handle(
            reportable: $subject,
            reason: $reason,
            note: is_string($note) ? $note : null,
            email: is_string($email) ? $email : null,
            userId: is_int($userId) ? $userId : null,
            ipAddress: $request->ip(),
        );

        return ApiResponse::item([
            'id' => (int) $report->getKey(),
            'status' => $report->status->value,
            'message' => __('api.reports.received'),
            'created_at' => ApiDate::attribute($report, 'created_at', $city->timezone),
        ], status: 201);
    }

    /**
     * L'oggetto della segnalazione, cercato **fra ciò che il pubblico vede**:
     * segnalare una bozza o un locale mai approvato non è possibile, perché
     * chi segnala non ha potuto vederli.
     */
    private function subject(StoreReportRequest $request): Model
    {
        $city = $this->city();
        $slug = $request->subjectSlug();

        $subject = $request->subjectType() === ReportSubject::Event
            ? Event::query()->inCity($city)->readable()->where('slug', $slug)->first()
            : Venue::query()->inCity($city)
                ->whereIn('status', [VenueStatus::Approved, VenueStatus::Suspended])
                ->where('slug', $slug)
                ->first();

        if (! $subject instanceof Model) {
            throw new ApiException(ApiErrorCode::NotFound);
        }

        return $subject;
    }

    /**
     * Chi ha già segnalato la stessa cosa per lo stesso motivo, e quella
     * segnalazione è ancora da leggere, non ne apre una seconda. Il
     * riconoscimento è per utente quando c'è un token, altrimenti per
     * indirizzo IP — che è l'unico appiglio quando l'account non esiste.
     */
    private function assertNotPending(Request $request, Model $subject, ReportReason $reason): void
    {
        $userId = $request->user('sanctum')?->getKey();

        $duplicate = Report::query()
            ->where('reportable_type', Relation::getMorphAlias($subject::class))
            ->where('reportable_id', $subject->getKey())
            ->where('reason', $reason)
            ->where('status', ReportStatus::Pending)
            ->when(
                is_int($userId),
                fn (Builder $query): Builder => $query->where('reporter_user_id', $userId),
                fn (Builder $query): Builder => $query->where('ip_address', $request->ip()),
            )
            ->exists();

        if ($duplicate) {
            throw new ApiException(ApiErrorCode::ReportAlreadyPending);
        }
    }
}
