<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Enums\ApiErrorCode;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\JsonResponse;

/**
 * L'unica forma che una risposta dell'API può avere (§13.6):
 *
 * ```json
 * {"data": [], "meta": {"next_cursor": "…", "has_more": true}}
 * {"error": {"code": "VALIDATION_FAILED", "message": "…", "fields": {}}}
 * ```
 *
 * Sta in un posto solo perché una seconda forma — anche una sola risposta con
 * `results` invece di `data` — costringerebbe ogni client a due strade.
 */
final class ApiResponse
{
    /**
     * Una lista paginata a cursore. `next_cursor` è `null` sull'ultima
     * pagina: `has_more` esiste comunque, perché un client non deve dedurre
     * un booleano dall'assenza di una stringa.
     *
     * @param  CursorPaginator<int, covariant object>  $paginator
     * @param  callable(object): array<string, mixed>  $transform
     * @param  array<string, mixed>  $meta
     */
    public static function page(CursorPaginator $paginator, callable $transform, array $meta = []): JsonResponse
    {
        $data = [];

        foreach ($paginator->items() as $item) {
            $data[] = $transform($item);
        }

        return new JsonResponse([
            'data' => $data,
            'meta' => [
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'has_more' => $paginator->hasMorePages(),
                ...$meta,
            ],
        ]);
    }

    /**
     * Una lista non paginata — configurazione, calendario, marcatori della
     * mappa — che porta comunque `meta`, così la forma resta una sola.
     *
     * @param  array<int|string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    public static function collection(array $data, array $meta = []): JsonResponse
    {
        return new JsonResponse([
            'data' => $data,
            'meta' => [
                'next_cursor' => null,
                'has_more' => false,
                ...$meta,
            ],
        ]);
    }

    /**
     * Una risorsa singola.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    public static function item(array $data, array $meta = [], int $status = 200): JsonResponse
    {
        $payload = ['data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return new JsonResponse($payload, $status);
    }

    /**
     * Lo stato lo decide il codice. `$status` serve al solo caso in cui il
     * codice sia generico e lo stato vero vada conservato — un 405 che
     * diventasse 400 manderebbe chi indaga dalla parte sbagliata.
     *
     * @param  array<string, array<int, string>>  $fields
     */
    public static function error(ApiErrorCode $code, ?string $message = null, array $fields = [], ?int $status = null): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $code->value,
                'message' => $message ?? $code->message(),
                'fields' => (object) $fields,
            ],
        ], $status ?? $code->status());
    }
}
