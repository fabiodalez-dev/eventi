<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * I codici di errore dell'API (§13.6).
 *
 * Il codice è la parte della risposta che un client può leggere con un
 * confronto: il messaggio cambia con la lingua e con il tempo, il codice no.
 * Per questo è un enum e non una stringa scritta dove capita.
 */
enum ApiErrorCode: string
{
    case ValidationFailed = 'VALIDATION_FAILED';
    case Unauthenticated = 'UNAUTHENTICATED';
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case Forbidden = 'FORBIDDEN';
    case NotFound = 'NOT_FOUND';
    case CityNotFound = 'CITY_NOT_FOUND';
    case Conflict = 'CONFLICT';
    case ReportAlreadyPending = 'REPORT_ALREADY_PENDING';
    case InvalidCursor = 'INVALID_CURSOR';
    case InvalidToken = 'INVALID_TOKEN';
    case InvalidRequest = 'INVALID_REQUEST';
    case RateLimited = 'RATE_LIMITED';
    case ServerError = 'SERVER_ERROR';

    /**
     * Lo stato HTTP che accompagna il codice: la corrispondenza è fissa,
     * altrimenti lo stesso errore uscirebbe con due stati diversi a seconda
     * di chi lo solleva.
     */
    public function status(): int
    {
        return match ($this) {
            self::ValidationFailed => 422,
            self::Unauthenticated, self::InvalidCredentials => 401,
            self::Forbidden => 403,
            self::NotFound, self::CityNotFound => 404,
            self::Conflict, self::ReportAlreadyPending => 409,
            self::InvalidCursor, self::InvalidRequest, self::InvalidToken => 400,
            self::RateLimited => 429,
            self::ServerError => 500,
        };
    }

    public function message(): string
    {
        return __('api.errors.'.mb_strtolower($this->value));
    }
}
