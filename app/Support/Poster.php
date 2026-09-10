<?php

declare(strict_types=1);

namespace App\Support;

use App\DTOs\SocialImage;
use App\Jobs\Media\GenerateOpenGraphImage;
use App\Models\Event;
use App\Services\Media\OpenGraphImage;
use App\Support\Media\ImageSet;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * L'indirizzo della locandina di un evento.
 *
 * La locandina può arrivare da due strade: la libreria media (`spatie/
 * laravel-medialibrary`, che è quella definitiva) oppure la colonna
 * `events.poster`, che l'import e l'inserimento redazionale rapido riempiono
 * con un percorso o con un indirizzo esterno. Card, scheda evento, dati
 * strutturati e anteprime social devono guardare **lo stesso** posto,
 * altrimenti la pagina mostra un'immagine e Facebook ne mostra un'altra.
 */
final class Poster
{
    public static function url(Event $event): ?string
    {
        $media = $event->getFirstMedia('poster');

        if ($media !== null) {
            /*
             * La variante `full` e non l'originale. §12.1 accetta anche gli
             * HEIC, che è il formato con cui un iPhone fotografa una
             * locandina: nessun motore di ricerca e nessuna applicazione di
             * messaggistica sa aprirli. La conversione WebP la aprono tutti, e
             * pesa meno. Se la coda non l'ha ancora prodotta, resta
             * l'originale — che è sempre meglio di un indirizzo che non
             * esiste.
             */
            return $media->hasGeneratedConversion('full')
                ? $media->getFullUrl('full')
                : $media->getFullUrl();
        }

        if (blank($event->poster)) {
            return null;
        }

        $poster = (string) $event->poster;

        return Str::startsWith($poster, ['http://', 'https://', '/'])
            ? $poster
            : Storage::disk('public')->url($poster);
    }

    /**
     * Lo stesso indirizzo, sempre assoluto: i dati strutturati e le anteprime
     * social vengono letti da macchine che non hanno un contesto di dominio.
     */
    public static function absoluteUrl(Event $event): ?string
    {
        $poster = self::url($event);

        if ($poster === null) {
            return null;
        }

        return Str::startsWith($poster, ['http://', 'https://']) ? $poster : url($poster);
    }

    /** @return list<string> */
    public static function schemaImages(Event $event): array
    {
        $urls = [];
        $media = $event->getFirstMedia('poster');
        foreach (['schema-square', 'schema-landscape', 'schema-wide'] as $name) {
            if ($media?->hasGeneratedConversion($name)) {
                $urls[] = $media->getFullUrl($name);
            }
        }
        $original = self::absoluteUrl($event);
        if ($original !== null) {
            $urls[] = $original;
        }

        return array_values(array_unique($urls));
    }

    /**
     * La locandina con tutte le sue varianti (§12.1), pronta per un
     * `<picture>`.
     */
    public static function imageSet(Event $event): ?ImageSet
    {
        $media = $event->getFirstMedia('poster');

        if ($media !== null) {
            return ImageSet::fromMedia($media);
        }

        $url = self::url($event);

        return $url === null ? null : ImageSet::fromUrl($url);
    }

    /**
     * L'immagine che finisce in `og:image` (§12.2): l'anteprima 1200×630
     * composta dalla pipeline se esiste, altrimenti la locandina nuda.
     *
     * **Se non esiste, viene chiesta e basta.** Comporla adesso costerebbe a
     * chi apre la pagina un secondo di attesa per un'immagine che non vedrà
     * mai — la vedrà chi riceverà il collegamento. Il lavoro va in coda ed è
     * unico per evento: la prossima visita, o il prossimo passaggio di un
     * robot social, troverà il file pronto.
     */
    public static function social(Event $event): ?SocialImage
    {
        if (config()->boolean('media.open_graph.enabled')) {
            $disk = Storage::disk(OpenGraphImage::disk());
            $path = OpenGraphImage::path($event);

            if ($disk->exists($path)) {
                $url = $disk->url($path);
                $url = Str::startsWith($url, ['http://', 'https://']) ? $url : url($url);

                return new SocialImage(
                    $url.'?v='.$disk->lastModified($path),
                    config()->integer('media.open_graph.width'),
                    config()->integer('media.open_graph.height'),
                );
            }

            GenerateOpenGraphImage::dispatch($event);
        }

        $fallback = self::absoluteUrl($event);

        if ($fallback === null) {
            return null;
        }

        $media = $event->getFirstMedia('poster');
        $width = $media?->getCustomProperty('width');
        $height = $media?->getCustomProperty('height');

        return new SocialImage(
            $fallback,
            is_numeric($width) ? (int) $width : null,
            is_numeric($height) ? (int) $height : null,
        );
    }
}
