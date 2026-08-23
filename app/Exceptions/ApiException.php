<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ApiErrorCode;
use RuntimeException;

/**
 * L'errore che l'API sa raccontare: un codice stabile, uno stato HTTP fisso
 * e — quando serve — i campi che non hanno superato la validazione (§13.6).
 *
 * Esiste perché `abort(400)` non basta: due errori con lo stesso stato
 * possono avere due codici diversi, e il codice è la parte che i client
 * leggono davvero. Il campo si chiama `errorCode` e non `code` perché
 * `Exception::$code` esiste già ed è un intero non readonly: sovrascriverlo
 * è un errore fatale di PHP, non una scelta di stile.
 */
final class ApiException extends RuntimeException
{
    /**
     * @param  array<string, array<int, string>>  $fields
     */
    public function __construct(
        public readonly ApiErrorCode $errorCode,
        ?string $message = null,
        public readonly array $fields = [],
    ) {
        parent::__construct($message ?? $errorCode->message());
    }

    public function status(): int
    {
        return $this->errorCode->status();
    }
}
