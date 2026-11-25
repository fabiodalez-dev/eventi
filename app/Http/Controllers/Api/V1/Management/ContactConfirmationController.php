<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Management\ContactConfirmationRequest;
use App\Http\Resources\V1\Management\ContactSettingsResource;
use App\Services\Contact\ContactEmailConfirmation;
use Illuminate\Http\JsonResponse;

/**
 * «Reinvia la conferma» del recapito del modulo di contatto, per il locale o
 * l'organizzazione. I tetti giornalieri sono quelli di
 * `ContactEmailConfirmation`, gli stessi del pulsante dei pannelli: sito e
 * app consumano lo stesso secchio.
 */
final class ContactConfirmationController extends Controller
{
    public function resend(ContactConfirmationRequest $request, ContactEmailConfirmation $confirmation): JsonResponse
    {
        $target = $request->target();

        if (blank($target->contact_email) || $target->hasConfirmedContactEmail()) {
            throw new ApiException(ApiErrorCode::ValidationFailed, null, [
                'contact_email' => [blank($target->contact_email) ? __('validation.required', ['attribute' => __('contact.recipient')]) : __('contact.confirmation.confirmed', ['email' => $target->contact_email])],
            ]);
        }

        if (! $confirmation->canSend($target)) {
            throw new ApiException(ApiErrorCode::RateLimited, __('contact.confirmation.limit'));
        }

        if (! $confirmation->send($target)) {
            throw new ApiException(ApiErrorCode::ServerError, __('contact.confirmation.failed'));
        }

        return response()->json(['data' => [
            'contact' => ContactSettingsResource::toArray($target),
            'message' => (string) __('contact.confirmation.resent', ['email' => $target->contact_email]),
        ]]);
    }
}
