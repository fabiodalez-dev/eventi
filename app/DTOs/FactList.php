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
 * L'elenco ordinato delle righe di una scheda tecnica (`events.facts`,
 * `venues.info`).
 *
 * Vale la stessa regola di `ExternalLinkList`: chi legge trova soltanto righe
 * utilizzabili, e non deve difendersi da niente. Le righe rotte spariscono
 * invece di far fallire la lettura di un evento salvato prima di una regola
 * più severa; a spiegare il rifiuto a chi compila il modulo pensa
 * `App\Rules\FactRows`.
 *
 * **Il tetto è dodici.** Una scheda tecnica è un colpo d'occhio: oltre la
 * dozzina di righe smette di esserlo e diventa un secondo testo, che è ciò per
 * cui esiste la descrizione.
 *
 * @implements Arrayable<int, array{label: string, value: string}>
 * @implements IteratorAggregate<int, Fact>
 */
final readonly class FactList implements Arrayable, Countable, IteratorAggregate, JsonSerializable
{
    public const int MAX_FACTS = 12;

    /**
     * @param  list<Fact>  $facts
     */
    private function __construct(public array $facts) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Accetta ciò che arriva davvero: l'elenco già tipizzato, le righe di un
     * ripetitore Filament (una mappa `{identificatore: riga}`, non una lista),
     * un JSON decodificato o ancora da decodificare, `null`.
     */
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

        $facts = [];

        foreach ($value as $row) {
            $fact = Fact::tryFrom($row);

            if ($fact === null) {
                continue;
            }

            $facts[] = $fact;

            if (count($facts) === self::MAX_FACTS) {
                break;
            }
        }

        return new self($facts);
    }

    public function isEmpty(): bool
    {
        return $this->facts === [];
    }

    public function isNotEmpty(): bool
    {
        return ! $this->isEmpty();
    }

    public function count(): int
    {
        return count($this->facts);
    }

    /**
     * @return Traversable<int, Fact>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->facts);
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public function toArray(): array
    {
        return array_map(static fn (Fact $fact): array => $fact->toArray(), $this->facts);
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
