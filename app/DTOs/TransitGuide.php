<?php

declare(strict_types=1);

namespace App\DTOs;

use ArrayIterator;
use Countable;
use Illuminate\Contracts\Support\Arrayable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * «Come arrivare» a un locale: l'elenco ordinato delle indicazioni di
 * `venues.transit`.
 *
 * Sta sul **locale** e non sull'evento perché cambia col luogo, non con la
 * serata: la fermata del tram davanti al teatro è la stessa a gennaio e a
 * luglio, e chiederla a chi inserisce ogni singola data significherebbe non
 * averla quasi mai.
 *
 * @implements Arrayable<int, array{mode: string, text: string}>
 * @implements IteratorAggregate<int, TransitLine>
 */
final readonly class TransitGuide implements Arrayable, Countable, IteratorAggregate, JsonSerializable
{
    /**
     * Sei righe coprono metro, tram, bus, treno, auto e bici. Oltre, l'elenco
     * smette di essere una risposta e diventa un orario del trasporto
     * pubblico, che non è quello che questa piattaforma pubblica.
     */
    public const int MAX_LINES = 6;

    /**
     * @param  list<TransitLine>  $lines
     */
    private function __construct(public array $lines) {}

    public static function empty(): self
    {
        return new self([]);
    }

    public static function fromMixed(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (! is_iterable($value)) {
            return self::empty();
        }

        $lines = [];

        foreach ($value as $row) {
            $line = TransitLine::tryFrom($row);

            if ($line === null) {
                continue;
            }

            $lines[] = $line;

            if (count($lines) === self::MAX_LINES) {
                break;
            }
        }

        return new self($lines);
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function isNotEmpty(): bool
    {
        return ! $this->isEmpty();
    }

    public function count(): int
    {
        return count($this->lines);
    }

    /**
     * @return Traversable<int, TransitLine>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->lines);
    }

    /**
     * @return list<array{mode: string, text: string}>
     */
    public function toArray(): array
    {
        return array_map(static fn (TransitLine $line): array => $line->toArray(), $this->lines);
    }

    /**
     * @return list<array{mode: string, text: string}>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
