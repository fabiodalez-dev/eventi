<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\ImportSource;
use Illuminate\Support\Str;

/**
 * La colonna `import_sources.mapping` (json) letta come configurazione, invece
 * che come array di cui indovinare le chiavi a ogni uso.
 *
 * ```json
 * {
 *   "exclude_keywords": ["chiuso", "ferie"],
 *   "timezone": "Europe/Rome",
 *   "auth": "basic"
 * }
 * ```
 *
 * Chi non dichiara nulla prende i valori di `config/import.php`; chi dichiara
 * `exclude_keywords` **sostituisce** l'elenco predefinito invece di aggiungersi
 * ad esso, perché la sorgente che se ne prende la briga vuole quell'elenco lì,
 * e un elenco che cresce di nascosto a ogni rilascio non è configurabile.
 */
final readonly class ImportMapping
{
    /**
     * @param  list<string>  $excludeKeywords
     */
    private function __construct(
        public array $excludeKeywords,
        public ?string $timezone,
        public ?string $auth,
    ) {}

    public static function fromSource(ImportSource $source): self
    {
        $mapping = is_array($source->mapping) ? $source->mapping : [];

        return new self(
            self::keywords($mapping['exclude_keywords'] ?? null),
            self::timezone($mapping['timezone'] ?? null),
            is_string($mapping['auth'] ?? null) ? strtolower($mapping['auth']) : null,
        );
    }

    /**
     * Vero quando il titolo contiene una delle parole di esclusione.
     *
     * Il confronto avviene su **parole intere** e su una forma normalizzata —
     * minuscole, senza accenti, punteggiatura ridotta a spazi. Una sottostringa
     * escluderebbe "Chiusura del festival" per via di "chiuso" che non c'è, e
     * una parola accentata scritta in due modi diversi sfuggirebbe al filtro
     * senza che nessuno se ne accorga.
     */
    public function excludes(string $title): bool
    {
        if ($this->excludeKeywords === []) {
            return false;
        }

        $words = self::words($title);

        if ($words === []) {
            return false;
        }

        foreach ($this->excludeKeywords as $keyword) {
            $needle = self::words($keyword);

            if ($needle === []) {
                continue;
            }

            if (self::contains($words, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $words
     * @param  list<string>  $needle
     */
    private static function contains(array $words, array $needle): bool
    {
        $size = count($needle);
        $limit = count($words) - $size;

        for ($index = 0; $index <= $limit; $index++) {
            if (array_slice($words, $index, $size) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $normalized = Str::lower(Str::ascii($text));
        $split = preg_split('/[^a-z0-9]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);

        return $split === false ? [] : $split;
    }

    /**
     * @return list<string>
     */
    private static function keywords(mixed $declared): array
    {
        if (! is_array($declared)) {
            /** @var list<string> $default */
            $default = config()->array('import.exclude_keywords');

            return $default;
        }

        $keywords = [];

        foreach ($declared as $keyword) {
            if (is_string($keyword) && trim($keyword) !== '') {
                $keywords[] = trim($keyword);
            }
        }

        return $keywords;
    }

    private static function timezone(mixed $declared): ?string
    {
        if (! is_string($declared) || trim($declared) === '') {
            return null;
        }

        return in_array(trim($declared), timezone_identifiers_list(), true) ? trim($declared) : null;
    }
}
