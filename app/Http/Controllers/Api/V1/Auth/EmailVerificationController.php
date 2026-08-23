<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\VerifyEmailRequest;
use App\Services\Account\SignedEmailVerification;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * `POST /v1/auth/verify-email` (§15.8).
 *
 * La verifica è la condizione di qualunque invio (§15.2): finché non avviene,
 * un account può salvare e non può ricevere. L'app raccoglie il collegamento
 * arrivato per posta e lo rimanda qui intero: è la firma a farne una prova, e
 * la firma copre l'indirizzo completo.
 */
final class EmailVerificationController extends Controller
{
    public function __invoke(VerifyEmailRequest $request, SignedEmailVerification $verification): JsonResponse
    {
        $user = $verification->verify((string) $request->validated('url'));

        if ($user === null) {
            throw new ApiException(ApiErrorCode::InvalidToken);
        }

        return ApiResponse::item(['message' => __('account.api.email_verified')]);
    }
}
