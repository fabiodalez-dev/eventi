<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Rules\RealImage;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;

/**
 * L'ingresso della pipeline di §12.1 nei due pannelli.
 *
 * `->image()` di Filament dichiara `accept="image/*"` e si ferma lì: è un
 * suggerimento al selettore di file del sistema operativo, non un controllo.
 * Il controllo è `App\Rules\RealImage`, che legge i byte.
 *
 * I tipi accettati sono elencati per esteso e non lasciati a `image/*` perché
 * un iPhone manda i propri HEIC dichiarandoli `application/octet-stream`: con
 * il solo `image/*` il selettore li mostrerebbe in grigio, e chi carica
 * penserebbe che la propria locandina non vada bene.
 */
final class ImageUpload
{
    public static function make(string $name): SpatieMediaLibraryFileUpload
    {
        return self::configure(SpatieMediaLibraryFileUpload::make($name));
    }

    public static function configure(SpatieMediaLibraryFileUpload $field): SpatieMediaLibraryFileUpload
    {
        return $field
            ->image()
            ->acceptedFileTypes([
                ...RealImage::acceptedMimeTypes(),
                ...array_map(static fn (string $extension): string => '.'.$extension, RealImage::acceptedExtensions()),
            ])
            ->maxSize((int) (config()->integer('media.max_upload_bytes') / 1024))
            ->rules([new RealImage]);
    }
}
