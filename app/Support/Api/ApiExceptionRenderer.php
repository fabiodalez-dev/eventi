<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Traduce le eccezioni in risposte dell'API (§13.6).
 *
 * Senza questo punto unico ogni eccezione uscirebbe con la forma che le dà
 * Laravel — `{"message": "..."}` per la validazione, una pagina HTML per un
 * 404 — e il client dovrebbe conoscerne tre. Qui la forma è una, e lo stato
 * HTTP lo decide `ApiErrorCode` (§13.6: 400/401/403/404/409/422/429/500).
 */
final class ApiExceptionRenderer
{
    public function __invoke(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $this->handles($request)) {
            return null;
        }

        $response = $this->translate($exception);

        if ($exception instanceof HttpExceptionInterface) {
            $response->withHeaders($exception->getHeaders());
        }

        return $response;
    }

    /**
     * Solo le rotte dell'API: il sito pubblico e i pannelli hanno le proprie
     * pagine di errore, e trasformarle in JSON sarebbe un peggioramento.
     */
    private function handles(Request $request): bool
    {
        return $request->is('api/*');
    }

    private function translate(Throwable $exception): JsonResponse
    {
        if ($exception instanceof ApiException) {
            return ApiResponse::error($exception->errorCode, $exception->getMessage(), $exception->fields);
        }

        if ($exception instanceof ValidationException) {
            /** @var array<string, array<int, string>> $fields */
            $fields = $exception->errors();

            return ApiResponse::error(ApiErrorCode::ValidationFailed, null, $fields);
        }

        if ($exception instanceof AuthenticationException) {
            return ApiResponse::error(ApiErrorCode::Unauthenticated);
        }

        if ($exception instanceof AuthorizationException) {
            return ApiResponse::error(ApiErrorCode::Forbidden);
        }

        if ($exception instanceof ModelNotFoundException) {
            return ApiResponse::error(ApiErrorCode::NotFound);
        }

        if ($exception instanceof HttpExceptionInterface) {
            return $this->fromStatus($exception->getStatusCode());
        }

        return ApiResponse::error(ApiErrorCode::ServerError, $this->serverMessage($exception));
    }

    /**
     * Le eccezioni HTTP portano già lo stato giusto: qui si trova il codice
     * che gli corrisponde. Uno stato senza codice dedicato — 405, 406, 415 —
     * conserva il proprio stato e prende il codice generico: trasformarlo in
     * 500 manderebbe chi indaga a cercare un guasto che non c'è.
     *
     * Il messaggio dell'eccezione **non** si propaga mai. Quelli del framework
     * sono in inglese ("Too Many Attempts.") e violerebbero la regola sulle
     * stringhe fuori da `lang/it`; quello di un 404 di rotta racconta i nomi
     * delle classi del progetto. I messaggi nostri viaggiano invece con
     * `ApiException`, che è gestita prima di arrivare qui.
     */
    private function fromStatus(int $status): JsonResponse
    {
        $code = match (true) {
            $status === 401 => ApiErrorCode::Unauthenticated,
            $status === 403 => ApiErrorCode::Forbidden,
            $status === 404 => ApiErrorCode::NotFound,
            $status === 409 => ApiErrorCode::Conflict,
            $status === 422 => ApiErrorCode::ValidationFailed,
            $status === 429 => ApiErrorCode::RateLimited,
            $status >= 500 => ApiErrorCode::ServerError,
            default => ApiErrorCode::InvalidRequest,
        };

        return ApiResponse::error($code, null, [], $status);
    }

    /**
     * Il messaggio vero di un errore interno esce solo in ambiente di
     * sviluppo: in produzione un messaggio di eccezione racconta la struttura
     * del codice a chi non deve conoscerla.
     */
    private function serverMessage(Throwable $exception): ?string
    {
        return config()->boolean('app.debug') ? $exception->getMessage() : null;
    }
}
