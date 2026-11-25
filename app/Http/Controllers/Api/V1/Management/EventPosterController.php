<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Management\EventPosterRequest;
use App\Models\User;
use App\Services\Management\ManagementImages;
use App\Support\Management\ManagementSubject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

/**
 * La locandina di un evento del locale o dell'organizzazione.
 *
 * Il file nuovo sostituisce il vecchio (raccolta `poster`, un file solo).
 * Su un evento pubblicato la locandina nuova rimanda in revisione quando la
 * pubblicazione non è autonoma: `sent_to_review` lo dice all'app.
 */
final class EventPosterController extends Controller
{
    /** Carica o sostituisce la locandina (campo multipart `image`). */
    public function store(EventPosterRequest $request, ManagementImages $images): JsonResponse
    {
        $file = $request->file('image');
        abort_unless($file instanceof UploadedFile, 422);

        return response()->json(['data' => $images->replacePoster($request->event(), ManagementSubject::require()->model, $this->user($request), $file)]);
    }

    /** Toglie la locandina. Non rimanda in revisione. */
    public function destroy(EventPosterRequest $request, ManagementImages $images): JsonResponse
    {
        return response()->json(['data' => $images->removePoster($request->event())]);
    }

    private function user(EventPosterRequest $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
