<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\ConsentCategory;

/**
 * La scelta espressa da un browser: chi l'ha espressa, a quale versione
 * dell'informativa si riferiva, e cosa ha acconsentito.
 *
 * È immutabile e sa leggersi e scriversi da sé nella forma compatta che va nel
 * cookie. Quella forma è **la stessa cosa** del registro in `consent_logs`
 * vista dall'altro lato: se le due divergessero, il sito si comporterebbe in un
 * modo e il registro proverebbe l'altro.
 */
final readonly class ConsentState
{
    /**
     * @param  array<string, bool>  $choices
     */
    public function __construct(
        public string $id,
        public string $version,
        public array $choices,
    ) {}

    /**
     * La scelta letta dal cookie, o `null` se non c'è, è illeggibile, oppure si
     * riferisce a una versione dell'informativa che non è più quella vigente —
     * caso in cui il banner deve tornare, perché le finalità sono cambiate.
     */
    public static function decode(?string $raw, string $currentVersion): ?self
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return null;
        }

        $id = $decoded['id'] ?? null;
        $version = $decoded['v'] ?? null;
        $choices = $decoded['c'] ?? null;

        if (! is_string($id) || ! is_string($version) || ! is_array($choices)) {
            return null;
        }

        if ($version !== $currentVersion) {
            return null;
        }

        $normalised = [];

        foreach (ConsentCategory::cases() as $category) {
            $normalised[$category->value] = ($choices[$category->value] ?? false) === true;
        }

        return new self($id, $version, $normalised);
    }

    /**
     * Le chiavi sono corte perché questo valore viaggia cifrato in un cookie a
     * ogni richiesta, comprese quelle delle immagini: `necessary` scritto per
     * esteso otto volte al giorno è banda regalata.
     */
    public function encode(): string
    {
        return (string) json_encode([
            'id' => $this->id,
            'v' => $this->version,
            'c' => $this->choices,
        ]);
    }

    public function allows(ConsentCategory $category): bool
    {
        return ($this->choices[$category->value] ?? false) === true;
    }
}
