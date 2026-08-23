<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Event;
use App\Support\Poster;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * La locandina nella forma che §13.2 impone: `{thumb, card, full, blurhash,
 * width, height}`.
 *
 * Le tre misure e i tre metadati **ci sono sempre**, anche quando valgono
 * `null`: un client che a volte trova la chiave e a volte no scrive due
 * strade per leggere la stessa cosa, e il contratto della v1 non cambia più.
 *
 * Le conversioni della libreria media appartengono alla pipeline di §12.1,
 * che è di un'altra fase: finché non esistono, le tre misure puntano al file
 * originale e `blurhash`, `width` e `height` restano nulli. Il giorno in cui
 * la pipeline le genererà, questo è l'unico punto da cui usciranno — la
 * forma della risposta non cambierà.
 */
final class PosterResource
{
    /**
     * @return array<string, mixed>|null
     */
    public static function toArray(Event $event): ?array
    {
        $media = $event->getFirstMedia('poster');
        $fallback = Poster::url($event);

        if ($media === null) {
            return $fallback === null ? null : [
                'thumb' => $fallback,
                'card' => $fallback,
                'full' => $fallback,
                'blurhash' => null,
                'width' => null,
                'height' => null,
            ];
        }

        $full = $media->getFullUrl();

        return [
            'thumb' => self::conversion($media, 'thumb', $full),
            'card' => self::conversion($media, 'card', $full),
            'full' => $full,
            'blurhash' => self::property($media, 'blurhash'),
            'width' => self::dimension($media, 'width'),
            'height' => self::dimension($media, 'height'),
        ];
    }

    private static function conversion(Media $media, string $name, string $fallback): string
    {
        return $media->hasGeneratedConversion($name) ? $media->getFullUrl($name) : $fallback;
    }

    private static function property(Media $media, string $key): ?string
    {
        $value = $media->getCustomProperty($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function dimension(Media $media, string $key): ?int
    {
        $value = $media->getCustomProperty($key);

        return is_numeric($value) ? (int) $value : null;
    }
}
