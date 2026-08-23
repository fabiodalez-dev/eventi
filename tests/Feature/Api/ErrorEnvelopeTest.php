<?php

declare(strict_types=1);

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Support\Api\ApiExceptionRenderer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Il traduttore delle eccezioni, provato direttamente: sono i casi che nessun
 * endpoint pubblico produce oggi (403, 500) ma che devono uscire nella forma
 * di §13.6 il giorno in cui si presenteranno.
 */
function rendi(Throwable $exception, string $path = 'api/v1/events'): ?JsonResponse
{
    return (new ApiExceptionRenderer)($exception, Request::create('/'.$path));
}

it('traduce un divieto in 403 con il codice giusto', function (): void {
    $response = rendi(new AuthorizationException);

    expect($response?->getStatusCode())->toBe(403)
        ->and($response?->getData(true)['error']['code'])->toBe(ApiErrorCode::Forbidden->value);
});

it('traduce un errore interno in 500 senza raccontare lo stack', function (): void {
    config()->set('app.debug', false);

    $response = rendi(new RuntimeException('Connessione al database perduta su host segreto'));

    $error = $response?->getData(true)['error'] ?? [];

    expect($response?->getStatusCode())->toBe(500)
        ->and($error['code'])->toBe(ApiErrorCode::ServerError->value)
        ->and($error['message'])->not->toContain('host segreto');
});

it('conserva lo stato di un errore HTTP che non ha un codice dedicato', function (): void {
    $response = rendi(new HttpException(415, 'Unsupported Media Type'));

    expect($response?->getStatusCode())->toBe(415)
        ->and($response?->getData(true)['error']['code'])->toBe(ApiErrorCode::InvalidRequest->value)
        /* I messaggi del framework sono in inglese e non escono mai. */
        ->and($response?->getData(true)['error']['message'])->not->toContain('Unsupported');
});

it('lascia stare tutto ciò che non è una rotta dell\'API', function (): void {
    expect(rendi(new AuthorizationException, 'eventi/oggi'))->toBeNull();
});

it('porta i campi non validi dentro l\'errore', function (): void {
    $response = rendi(new ApiException(ApiErrorCode::ValidationFailed, 'Non va bene', ['titolo' => ['manca']]));

    expect($response?->getStatusCode())->toBe(422)
        ->and($response?->getData(true)['error']['fields'])->toBe(['titolo' => ['manca']]);
});
