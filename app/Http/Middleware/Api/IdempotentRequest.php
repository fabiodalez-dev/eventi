<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

final class IdempotentRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null) {
            return $next($request);
        }

        if (preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $key) !== 1) {
            throw new ApiException(ApiErrorCode::InvalidRequest, __('api.errors.invalid_idempotency_key'));
        }

        $user = $request->user('sanctum');
        $installation = $request->header('X-Installation-ID');
        $actor = $user === null
            ? (is_string($installation) && preg_match('/^[A-Za-z0-9._:-]{16,64}$/', $installation) === 1
                ? 'installation:'.$installation
                : 'ip:'.($request->ip() ?? 'anonymous'))
            : 'user:'.$user->getAuthIdentifier();

        /* Query string e corpo fanno parte dell'operazione. Senza la query,
           la stessa chiave usata su `?city=padova` e `?city=vicenza`
           restituirebbe la risposta della prima citta'. I prefissi dell'attore
           impediscono inoltre che l'utente 42 collida con una installazione
           anonima che si dichiara semplicemente "42". */
        $target = $request->path().'?'.http_build_query($request->query());
        $cacheKey = 'api-idempotency:'.hash('sha256', $actor.'|'.$request->method().'|'.$target.'|'.$key);
        $requestHash = hash('sha256', $target.'|'.$request->getContent());
        $stored = Cache::get($cacheKey);

        if (is_array($stored)) {
            return $this->replay($stored, $requestHash);
        }

        return Cache::lock($cacheKey.':lock', 15)->block(5, function () use ($cacheKey, $requestHash, $next, $request): Response {
            $stored = Cache::get($cacheKey);

            if (is_array($stored)) {
                return $this->replay($stored, $requestHash);
            }

            $response = $next($request);

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                Cache::put($cacheKey, [
                    'request_hash' => $requestHash,
                    'status' => $response->getStatusCode(),
                    'body' => (string) $response->getContent(),
                ], now()->addDay());
            }

            return $response;
        });
    }

    /** @param array{request_hash: string, status: int, body: string} $stored */
    private function replay(array $stored, string $requestHash): JsonResponse
    {
        if (! hash_equals($stored['request_hash'], $requestHash)) {
            throw new ApiException(ApiErrorCode::Conflict, __('api.errors.idempotency_conflict'));
        }

        $decoded = json_decode($stored['body'], true);
        $response = new JsonResponse(is_array($decoded) ? $decoded : null, $stored['status']);
        $response->headers->set('Idempotency-Replayed', 'true');

        return $response;
    }
}
