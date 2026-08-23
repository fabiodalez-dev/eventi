<?php

declare(strict_types=1);

namespace App\Services\Geo;

use Illuminate\Database\Eloquent\Builder;

/**
 * Implementazione per MariaDB.
 *
 * `withinRadius` lavora in due tempi, e l'ordine conta:
 *
 * 1. `MBRContains(envelope, colonna)` — è l'unica forma che MariaDB risolve con
 *    lo SPATIAL INDEX. Da sola restituirebbe però un quadrato, non un cerchio.
 * 2. `ST_Distance_Sphere(colonna, punto) <= raggio` — raffina il quadrato in
 *    cerchio sul sottoinsieme già ridotto dal passo 1.
 *
 * Il lato dell'envelope si ricava dal raggio: un grado di latitudine vale
 * ~111.32 km ovunque, un grado di longitudine vale `111.32 * cos(lat)` km e si
 * accorcia salendo verso i poli — per questo il delta di longitudine si divide
 * per il coseno della latitudine.
 */
final class MariaDbGeoQuery implements GeoQueryInterface
{
    /**
     * Lunghezza in chilometri di un grado di latitudine.
     */
    private const KM_PER_DEGREE = 111.32;

    public function withinRadius(Builder $query, float $lat, float $lng, float $radiusKm, string $column): Builder
    {
        [$minLng, $minLat, $maxLng, $maxLat] = $this->envelope($lat, $lng, $radiusKm);

        $this->withinBounds($query, $minLng, $minLat, $maxLng, $maxLat, $column);

        $query->whereRaw(
            sprintf('ST_Distance_Sphere(%s, ST_GeomFromText(?, 0)) <= ?', $this->wrap($column)),
            [$this->pointWkt($lat, $lng), $radiusKm * 1000],
        );

        return $query;
    }

    public function withinBounds(Builder $query, float $minLng, float $minLat, float $maxLng, float $maxLat, string $column): Builder
    {
        $query->whereRaw(
            sprintf('MBRContains(ST_GeomFromText(?, 0), %s)', $this->wrap($column)),
            [$this->polygonWkt($minLng, $minLat, $maxLng, $maxLat)],
        );

        return $query;
    }

    /**
     * Il punto entra nell'espressione come testo e non come parametro. Non è
     * una scorciatoia: quando questa select viene usata per ordinare, Laravel
     * la ricopia dentro il `where` del cursore di `cursorPaginate()` senza
     * portarsi dietro il proprio binding, e un segnaposto lì dentro consumerebbe
     * il parametro del confronto disallineando tutti gli altri. Il WKT non è un
     * dato d'ingresso: è due `float` formattati da `pointWkt()`, che non può
     * produrre nulla oltre a cifre, punto, spazio e parentesi.
     */
    public function distanceSelect(Builder $query, float $lat, float $lng, string $column, string $as): Builder
    {
        $query->selectRaw(sprintf(
            "ST_Distance_Sphere(%s, ST_GeomFromText('%s', 0)) as %s",
            $this->wrap($column),
            $this->pointWkt($lat, $lng),
            $this->wrap($as),
        ));

        return $query;
    }

    /**
     * Rettangolo che circoscrive il cerchio di raggio dato.
     *
     * @return array{0: float, 1: float, 2: float, 3: float} minLng, minLat, maxLng, maxLat
     */
    private function envelope(float $lat, float $lng, float $radiusKm): array
    {
        $latDelta = $radiusKm / self::KM_PER_DEGREE;

        // Ai poli il coseno tende a zero e il delta esploderebbe: si limita il
        // divisore, ottenendo un envelope largo ma pur sempre valido.
        $cosine = max(abs(cos(deg2rad($lat))), 0.01);
        $lngDelta = $radiusKm / (self::KM_PER_DEGREE * $cosine);

        return [
            max($lng - $lngDelta, -180.0),
            max($lat - $latDelta, -90.0),
            min($lng + $lngDelta, 180.0),
            min($lat + $latDelta, 90.0),
        ];
    }

    private function pointWkt(float $lat, float $lng): string
    {
        return sprintf('POINT(%s %s)', $this->number($lng), $this->number($lat));
    }

    private function polygonWkt(float $minLng, float $minLat, float $maxLng, float $maxLat): string
    {
        $corners = [
            [$minLng, $minLat],
            [$maxLng, $minLat],
            [$maxLng, $maxLat],
            [$minLng, $maxLat],
            [$minLng, $minLat],
        ];

        $points = array_map(
            fn (array $corner): string => $this->number($corner[0]).' '.$this->number($corner[1]),
            $corners,
        );

        return 'POLYGON(('.implode(', ', $points).'))';
    }

    /**
     * Formato fisso: evita la notazione esponenziale e la virgola decimale
     * di certe locale, che MariaDB rifiuterebbe dentro il WKT.
     */
    private function number(float $value): string
    {
        return sprintf('%.8F', $value);
    }

    /**
     * Protegge gli identificatori: la colonna arriva dal codice, non
     * dall'utente, ma un `whereRaw` non deve mai fidarsi per abitudine.
     */
    private function wrap(string $column): string
    {
        $segments = array_map(
            fn (string $segment): string => '`'.str_replace('`', '', $segment).'`',
            explode('.', $column),
        );

        return implode('.', $segments);
    }
}
