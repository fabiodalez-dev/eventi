<?php

declare(strict_types=1);

namespace App\DTOs\Editorial;

/**
 * Un riquadro del riepilogo della redazione: etichetta, numero già scritto
 * come lo mostra il pannello («12», «38%», «—», «1.250,00»), la frase sotto,
 * il colore e dove porta.
 *
 * `count` è il numero nudo (o `null` quando il pannello scrive «—»), per chi
 * deve confrontarlo o colorarlo; `value` è quello da mostrare. `tone` usa i
 * colori di Filament (`warning`, `danger`, `success`, `gray`), `null` quando
 * il pannello non ne dà uno.
 */
final readonly class DeskStat
{
    public function __construct(
        public string $key,
        public string $label,
        public string $value,
        public int|float|null $count,
        public ?string $description = null,
        public ?string $tone = null,
        public ?DeskTarget $target = null,
    ) {}

    /**
     * @return array{key: string, label: string, value: string, count: int|float|null, description: string|null, tone: string|null, target: array<string, mixed>|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'value' => $this->value,
            'count' => $this->count,
            'description' => $this->description,
            'tone' => $this->tone,
            'target' => $this->target?->toArray(),
        ];
    }
}
