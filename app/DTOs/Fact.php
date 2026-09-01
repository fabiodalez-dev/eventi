<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Una riga di scheda tecnica: **un'etichetta e un valore**.
 *
 * È la forma di `events.facts` (capienza, apertura porte, età minima) e di
 * `venues.info` (guardaroba, regole della sala): due colonne diverse, la
 * stessa struttura, perché nel disegno di riferimento sono la stessa tabella
 * — a sinistra cosa si vuole sapere, a destra la risposta.
 *
 * Nessuna delle due parti può essere vuota: una riga con la sola etichetta è
 * una domanda senza risposta e nella scheda pubblica sarebbe una cella vuota
 * (§8.6).
 */
final readonly class Fact
{
    /**
     * L'etichetta è una o due parole («Apertura porte»). Oltre, non è più
     * un'intestazione di riga ma una frase, e la colonna sinistra della
     * tabella andrebbe a capo tre volte.
     */
    public const int MAX_LABEL_LENGTH = 40;

    /**
     * Il valore è un dato, non un paragrafo: «19:30», «1.200 persone»,
     * «Vietato ai minori di 18 anni». Il racconto sta nella descrizione.
     */
    public const int MAX_VALUE_LENGTH = 120;

    private function __construct(
        public string $label,
        public string $value,
    ) {}

    public static function make(string $label, string $value): self
    {
        return new self(trim($label), trim($value));
    }

    /**
     * La riga come arriva da un modulo, da un JSON o da un import. Se non è
     * utilizzabile si ottiene `null`, mai un oggetto a metà.
     */
    public static function tryFrom(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value->isAcceptable() ? $value : null;
        }

        if (! is_array($value)) {
            return null;
        }

        $fact = self::make(self::text($value['label'] ?? null), self::text($value['value'] ?? null));

        return $fact->isAcceptable() ? $fact : null;
    }

    public function isAcceptable(): bool
    {
        return $this->label !== ''
            && $this->value !== ''
            && mb_strlen($this->label) <= self::MAX_LABEL_LENGTH
            && mb_strlen($this->value) <= self::MAX_VALUE_LENGTH;
    }

    /**
     * @return array{label: string, value: string}
     */
    public function toArray(): array
    {
        return ['label' => $this->label, 'value' => $this->value];
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
