<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\AccessibilityFeature;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * L'accessibilità dichiarata di un locale: `venues.accessibility`, non più un
 * JSON libero ma le sei voci di `AccessibilityFeature`.
 *
 * **Tre stati, non due.** Una voce può essere presente (`true`), assente
 * (`false`) o **non dichiarata** (chiave mancante). La differenza non è
 * un cavillo: «non lo sappiamo» mostrato come «no» toglie a qualcuno un posto
 * dove poteva andare, e mostrato come «sì» lo manda a sbattere contro uno
 * scalino. Nel JSON finiscono soltanto le chiavi dichiarate, e chi legge
 * riceve `null` per le altre.
 *
 * Sul filtro di §11.3 l'unica risposta accettabile è `true`: `declares()` non
 * si usa mai per decidere se un locale entra in «accessibile».
 *
 * @implements Arrayable<string, bool>
 */
final readonly class AccessibilityProfile implements Arrayable, JsonSerializable
{
    /**
     * La chiave usata fino al 2026-09-01, quando `accessibility` era un JSON
     * libero con un solo interruttore. È qui e non nella migration perché i
     * dati arrivano anche da fuori — import, API, un backup ripristinato — e
     * chi legge deve continuare a capirli.
     */
    private const LEGACY_WHEELCHAIR_KEY = 'wheelchair';

    /**
     * @param  array<string, bool>  $features  chiavi di `AccessibilityFeature`, solo quelle dichiarate
     */
    private function __construct(public array $features) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Accetta la mappa salvata, le caselle di un modulo, un JSON, `null`, e la
     * vecchia forma `{"wheelchair": true}`, che viene letta come «ingresso
     * senza scalini» — l'unica cosa che quel campo abbia mai voluto dire.
     */
    public static function fromMixed(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (! is_array($value)) {
            return self::empty();
        }

        $features = [];

        foreach (AccessibilityFeature::cases() as $feature) {
            if (self::isDeclared($value, $feature->value)) {
                $features[$feature->value] = self::flag($value[$feature->value]);
            }
        }

        if (! array_key_exists(AccessibilityFeature::StepFreeEntrance->value, $features)
            && self::isDeclared($value, self::LEGACY_WHEELCHAIR_KEY)) {
            $features[AccessibilityFeature::StepFreeEntrance->value] = self::flag($value[self::LEGACY_WHEELCHAIR_KEY]);
        }

        return new self($features);
    }

    /**
     * Costruisce il profilo da un elenco di voci **presenti**: tutto ciò che
     * non è nell'elenco resta non dichiarato.
     *
     * @param  iterable<int, AccessibilityFeature|string>  $features
     */
    public static function of(iterable $features): self
    {
        $map = [];

        foreach ($features as $feature) {
            $case = $feature instanceof AccessibilityFeature
                ? $feature
                : AccessibilityFeature::tryFrom((string) $feature);

            if ($case !== null) {
                $map[$case->value] = true;
            }
        }

        return new self($map);
    }

    public function has(AccessibilityFeature $feature): bool
    {
        return ($this->features[$feature->value] ?? false) === true;
    }

    public function declares(AccessibilityFeature $feature): bool
    {
        return array_key_exists($feature->value, $this->features);
    }

    /**
     * Le sole voci presenti, nell'ordine dell'enum: è l'elenco che la scheda
     * pubblica spunta, e una voce dichiarata assente non ci compare.
     *
     * @return list<AccessibilityFeature>
     */
    public function available(): array
    {
        return array_values(array_filter(
            AccessibilityFeature::cases(),
            fn (AccessibilityFeature $feature): bool => $this->has($feature),
        ));
    }

    public function isEmpty(): bool
    {
        return $this->available() === [];
    }

    public function isNotEmpty(): bool
    {
        return ! $this->isEmpty();
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return $this->features;
    }

    /**
     * @return array<string, bool>
     */
    public function jsonSerialize(): array
    {
        return $this->features;
    }

    /**
     * `null` e stringa vuota **non** sono dichiarazioni: sono la casella
     * lasciata sul segnaposto in un modulo, cioè «non lo so». Trattarle come
     * `false` è l'errore che questa classe esiste per non fare.
     *
     * @param  array<array-key, mixed>  $value
     */
    private static function isDeclared(array $value, string $key): bool
    {
        return array_key_exists($key, $value)
            && $value[$key] !== null
            && $value[$key] !== '';
    }

    private static function flag(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
