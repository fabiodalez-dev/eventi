<?php

declare(strict_types=1);

namespace App\Services\Media;

use Imagick;
use Throwable;

final class ImageSafety
{
    public static function limitResources(): void
    {
        if (! extension_loaded('imagick')) {
            return;
        }
        foreach ([Imagick::RESOURCETYPE_MEMORY => 64 * 1024 * 1024, Imagick::RESOURCETYPE_MAP => 128 * 1024 * 1024,
            Imagick::RESOURCETYPE_DISK => 1024 * 1024 * 1024,
            Imagick::RESOURCETYPE_THREAD => 1] as $resource => $limit) {
            $existing = Imagick::getResourceLimit($resource);
            Imagick::setResourceLimit($resource, min($existing, $limit));
        }
    }

    /** @return array{int, int}|null */
    public static function dimensions(string $path): ?array
    {
        self::limitResources();
        if (! extension_loaded('imagick')) {
            $size = @getimagesize($path);

            return $size === false ? null : [(int) $size[0], (int) $size[1]];
        }
        $image = new Imagick;
        try {
            $image->pingImage($path);
            if ($image->getNumberImages() > 100) {
                return null;
            }
            $pixels = 0;
            $size = null;
            foreach ($image as $frame) {
                $width = $frame->getImageWidth();
                $height = $frame->getImageHeight();
                $pixels += $width * $height;
                $size ??= [$width, $height];
                if ($pixels > config()->integer('media.max_pixels')) {
                    return [config()->integer('media.max_pixels') + 1, 1];
                }
            }

            return $size;
        } catch (Throwable) {
            return null;
        } finally {
            $image->clear();
        }
    }

    public static function allowed(string $path): bool
    {
        $size = self::dimensions($path);

        return $size !== null && $size[0] > 0 && $size[1] > 0
            && config()->integer('media.max_pixels') >= $size[0] * $size[1];
    }
}
