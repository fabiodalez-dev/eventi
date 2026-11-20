<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Http\Request;
use WeakMap;

/**
 * Un valore calcolato una volta per richiesta.
 *
 * Serve contro le domande ripetute al database dentro la stessa pagina: una
 * policy chiesta per ogni data di un evento, una finestra di date chiesta da
 * due pezzi della home. La memoria sta sull'oggetto della richiesta, che è
 * nuovo a ogni richiesta (anche nei test), e sparisce con lei: non porta mai
 * un valore vecchio da una richiesta all'altra, come farebbe una memoria
 * legata a un utente o a un modello che sopravvive.
 */
final class RequestMemo
{
    /** @var WeakMap<Request, array<string, mixed>>|null */
    private static ?WeakMap $values = null;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function remember(string $key, Closure $callback): mixed
    {
        self::$values ??= new WeakMap;
        $request = request();

        if (array_key_exists($key, self::$values[$request] ?? [])) {
            return self::$values[$request][$key];
        }

        $value = $callback();

        /* Si rilegge la memoria dopo il calcolo: il calcolo può averne
           ricordati altri (una chiamata annidata), e una copia presa prima li
           cancellerebbe. */
        $values = self::$values[$request] ?? [];
        $values[$key] = $value;
        self::$values[$request] = $values;

        return $value;
    }
}
