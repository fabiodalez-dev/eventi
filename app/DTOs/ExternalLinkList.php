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
 * L'elenco ordinato dei link esterni di un evento.
 *
 * È il tipo che `Event::$external_links` restituisce: mai un array grezzo, che
 * ogni lettore reinterpreterebbe a modo suo. Chi legge trova soltanto righe
 * utilizzabili — etichetta piena, indirizzo `http`/`https` con host reale — e
 * non deve difendersi da niente: la difesa è già avvenuta qui.
 *
 * **Il tetto è otto.** Non è una preferenza estetica: un elenco di link in
 * coda a una scheda evento è un invito a trasformare la scheda in una pagina
 * di rimandi, e ogni link esterno è autorità che esce dal dominio. Otto voci
 * bastano ai social del locale, al sito ufficiale e alla rassegna stampa.
 *
 * @implements Arrayable<int, array{label: string, url: string}>
 * @implements IteratorAggregate<int, ExternalLink>
 */
final readonly class ExternalLinkList implements Arrayable, Countable, IteratorAggregate, JsonSerializable
{
    public const int MAX_LINKS = 8;

    /**
     * @param  list<ExternalLink>  $links
     */
    private function __construct(public array $links) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Accetta ciò che arriva davvero: l'elenco già tipizzato, le righe di un
     * ripetitore Filament (che sono una mappa `{identificatore: riga}`, non
     * una lista), un JSON decodificato, `null`.
     *
     * Le righe inutilizzabili non fanno fallire nulla: spariscono. Rifiutare
     * qui significherebbe rendere impossibile leggere un evento salvato prima
     * di una regola più severa, e la regola che *spiega* il rifiuto è
     * `App\Rules\ExternalLinks`, che parla a chi sta compilando il modulo.
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

        $links = [];

        foreach ($value as $row) {
            $link = ExternalLink::tryFrom($row);

            if ($link === null) {
                continue;
            }

            $links[] = $link;

            if (count($links) === self::MAX_LINKS) {
                break;
            }
        }

        return new self($links);
    }

    public function isEmpty(): bool
    {
        return $this->links === [];
    }

    public function isNotEmpty(): bool
    {
        return ! $this->isEmpty();
    }

    public function count(): int
    {
        return count($this->links);
    }

    /**
     * @return Traversable<int, ExternalLink>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->links);
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    public function toArray(): array
    {
        return array_map(static fn (ExternalLink $link): array => $link->toArray(), $this->links);
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
