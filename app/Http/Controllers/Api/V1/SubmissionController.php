<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateEventSubmission;
use App\Http\Controllers\Api\V1\Concerns\InteractsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSubmissionRequest;
use App\Support\Api\ApiDate;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * `POST /v1/submissions` (§13.1).
 *
 * Chi propone non pubblica: apre una pratica che nasce `pending` e resta in
 * coda di moderazione (§14.6). La risposta lo dice apertamente — una proposta
 * che sembra pubblicata e non compare da nessuna parte fa perdere più fiducia
 * di un rifiuto motivato.
 */
final class SubmissionController extends Controller
{
    use InteractsWithApi;

    public function __invoke(StoreSubmissionRequest $request, CreateEventSubmission $action): JsonResponse
    {
        $city = $this->city();
        $data = $request->validated();

        $submission = $action->handle($city, [
            'title' => (string) $data['title'],
            'raw_text' => isset($data['raw_text']) ? (string) $data['raw_text'] : null,
            'venue_hint' => isset($data['venue_hint']) ? (string) $data['venue_hint'] : null,
            'venue_id' => isset($data['venue_id']) ? (int) $data['venue_id'] : null,
            'starts_at_hint' => isset($data['starts_at_hint']) ? (string) $data['starts_at_hint'] : null,
            'contact_name' => isset($data['contact_name']) ? (string) $data['contact_name'] : null,
            'contact_email' => (string) $data['contact_email'],
        ], $request->ip());

        return ApiResponse::item([
            'id' => (int) $submission->getKey(),
            'status' => $submission->status->value,
            'message' => __('api.submissions.received'),
            'created_at' => ApiDate::attribute($submission, 'created_at', $city->timezone),
        ], status: 201);
    }
}
