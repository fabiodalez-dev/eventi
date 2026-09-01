<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\TransitMode;

/**
 * Una riga di «Come arrivare»: **un mezzo e una indicazione**.
 *
 * Il mezzo è un `TransitMode` — vocabolario chiuso, perché è un'etichetta
 * larga sei caratteri e va tradotta. Il testo è la parte che una persona legge
 * davvero: quale linea, quale fermata, quanti minuti a piedi.
 */
final readonly class TransitLine
{
    /**
     * Un'indicazione è una frase o due: «Tram 6, fermata Ospedali, poi cinque
     * minuti a piedi lungo via Giustiniani». Oltre, non si legge più in piedi
     * davanti alla fermata.
     */
    public const int MAX_TEXT_LENGTH = 200;

    private function __construct(
        public TransitMode $mode,
        public string $text,
    ) {}

    public static function make(TransitMode $mode, string $text): self
    {
        return new self($mode, trim($text));
    }

    public static function tryFrom(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value->isAcceptable() ? $value : null;
        }

        if (! is_array($value)) {
            return null;
        }

        $mode = $value['mode'] ?? null;
        $mode = $mode instanceof TransitMode ? $mode : (is_string($mode) ? TransitMode::tryFrom($mode) : null);

        if ($mode === null) {
            return null;
        }

        $line = self::make($mode, self::text($value['text'] ?? null));

        return $line->isAcceptable() ? $line : null;
    }

    public function isAcceptable(): bool
    {
        return $this->text !== '' && mb_strlen($this->text) <= self::MAX_TEXT_LENGTH;
    }

    /**
     * @return array{mode: string, text: string}
     */
    public function toArray(): array
    {
        return ['mode' => $this->mode->value, 'text' => $this->text];
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
