<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

/**
 * La richiesta della mappa: gli stessi filtri della lista (§11.6, «filtri
 * condivisi con la lista») più il rettangolo inquadrato.
 *
 * Il rettangolo arriva come `bbox=minLng,minLat,maxLng,maxLat`, l'ordine di
 * §13.3 e quello che usano OpenStreetMap e GeoJSON. Un rettangolo malformato
 * non è un errore da mostrare: la mappa si limita a inquadrare la città, che è
 * ciò che fa anche alla prima apertura.
 */
final class MapBoundsRequest extends EventFilterRequest
{
    /**
     * Latitudini e longitudini oltre questi limiti non esistono: un rettangolo
     * che le contiene viene dal browser di qualcun altro, non dalla mappa.
     */
    private const LIMITS = [180.0, 90.0, 180.0, 90.0];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'bbox' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Il rettangolo inquadrato, oppure `null` se non ne è arrivato uno valido.
     *
     * @return array{min_lng: float, min_lat: float, max_lng: float, max_lat: float}|null
     */
    public function bounds(): ?array
    {
        $raw = $this->validated('bbox');

        if (! is_string($raw)) {
            return null;
        }

        $parts = explode(',', $raw);

        if (count($parts) !== 4) {
            return null;
        }

        $values = [];

        foreach ($parts as $index => $part) {
            $part = trim($part);

            if (! is_numeric($part) || abs((float) $part) > self::LIMITS[$index]) {
                return null;
            }

            $values[] = (float) $part;
        }

        [$minLng, $minLat, $maxLng, $maxLat] = $values;

        /* Un rettangolo rovesciato o schiacciato non inquadra niente. */
        if ($minLng >= $maxLng || $minLat >= $maxLat) {
            return null;
        }

        return [
            'min_lng' => $minLng,
            'min_lat' => $minLat,
            'max_lng' => $maxLng,
            'max_lat' => $maxLat,
        ];
    }
}
