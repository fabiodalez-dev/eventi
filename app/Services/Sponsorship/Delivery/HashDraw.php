<?php

declare(strict_types=1);

namespace App\Services\Sponsorship\Delivery;

use App\Enums\SponsorshipPlacement;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Un caso che è lo stesso per tutti dentro una casella di tempo.
 *
 * Serve all'apertura della home, che sta dentro la pagina salvata in cache:
 * chiunque la ridisegni nella stessa casella (città, collocazione, mezz'ora)
 * deve ottenere la stessa scelta, altrimenti la quota dipenderebbe da quando
 * la cache si rigenera e non da ciò che è stato comprato.
 *
 * La sequenza è ripetibile: la prima chiamata dà sempre lo stesso numero, la
 * seconda un altro numero ma sempre quello, e così via. Due oggetti costruiti
 * con la stessa casella danno la stessa sequenza.
 */
final class HashDraw implements Draw
{
    /** 13 cifre esadecimali sono 52 bit: stanno in un intero e in un float senza perdite. */
    private const DIGITS = 13;

    private int $counter = 0;

    public function __construct(
        private readonly int $cityId,
        private readonly string $placement,
        private readonly int $slot,
    ) {}

    /**
     * La casella che contiene questo istante, lunga `$slotMinutes` minuti.
     *
     * Le caselle sono allineate al tempo Unix: con 30 minuti cominciano allo
     * scoccare dell'ora e della mezz'ora, in qualunque fuso.
     */
    public static function forSlot(int $cityId, SponsorshipPlacement|string $placement, CarbonInterface $now, int $slotMinutes): self
    {
        return new self($cityId, $placement instanceof SponsorshipPlacement ? $placement->value : $placement, self::slotIndex($now, $slotMinutes));
    }

    public static function slotIndex(CarbonInterface $now, int $slotMinutes): int
    {
        if ($slotMinutes < 1) {
            throw new InvalidArgumentException('A draw slot lasts at least one minute.');
        }

        return intdiv($now->getTimestamp(), $slotMinutes * 60);
    }

    /**
     * La durata della casella dell'apertura: quella scritta in
     * `sponsorship.hero_slot_minutes`, oppure, se vuota, la durata della cache
     * di pagina. Letta ogni volta, mai copiata.
     */
    public static function slotMinutes(): int
    {
        $minutes = config('sponsorship.hero_slot_minutes') ?? config('page_cache.ttl_minutes');

        return max(1, is_numeric($minutes) ? (int) $minutes : 30);
    }

    public function slot(): int
    {
        return $this->slot;
    }

    public function fraction(): float
    {
        $hash = hash('sha256', $this->cityId.'|'.$this->placement.'|'.$this->slot.'|'.$this->counter++);

        return hexdec(substr($hash, 0, self::DIGITS)) / (16 ** self::DIGITS);
    }
}
