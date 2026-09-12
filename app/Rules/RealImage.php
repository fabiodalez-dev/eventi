<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\ImageType;
use App\Services\Media\ImageSafety;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Il primo anello della pipeline di §12.1: **validazione MIME reale**.
 *
 * La regola `image` di Laravel si fida di `getimagesize()`, che non conosce
 * HEIC, e `mimes:jpg` guarda l'estensione. Qui il tipo si legge dai byte
 * (`App\Enums\ImageType`) e si confronta con l'estensione dichiarata: se non
 * combaciano il file viene rifiutato, perché è esattamente la forma di un
 * caricamento ostile.
 *
 * Il tetto di 12 MB e le misure minime stanno in `config/media.php` insieme al
 * resto della pipeline: qui si applicano, non si decidono.
 */
final class RealImage implements ValidationRule
{
    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail(__('validation.custom.image.invalid'));

            return;
        }

        if (! $value->isValid()) {
            $fail(__('validation.custom.image.upload_failed'));

            return;
        }

        $max = config()->integer('media.max_upload_bytes');

        if ($value->getSize() > $max) {
            $fail(__('validation.custom.image.too_large', ['max' => (int) round($max / 1024 / 1024)]));

            return;
        }

        $type = ImageType::detect($value->getPathname());

        if ($type === null) {
            $fail(__('validation.custom.image.unsupported', [
                'formats' => implode(', ', $this->acceptedExtensions()),
            ]));

            return;
        }

        $extension = strtolower($value->getClientOriginalExtension());

        if ($extension !== '' && ! in_array($extension, $type->extensions(), true)) {
            $fail(__('validation.custom.image.extension_mismatch', [
                'declared' => $extension,
                'real' => $type->extension(),
            ]));

            return;
        }

        $this->guardDimensions($value, $fail);
    }

    /**
     * Le estensioni che la pipeline accetta, in ordine e senza ripetizioni:
     * è l'elenco che compare nel messaggio d'errore e nell'attributo
     * `accept` del modulo.
     *
     * @return list<string>
     */
    public static function acceptedExtensions(): array
    {
        $extensions = [];

        foreach (ImageType::cases() as $type) {
            foreach ($type->extensions() as $extension) {
                $extensions[$extension] = true;
            }
        }

        return array_keys($extensions);
    }

    /**
     * @return list<string>
     */
    public static function acceptedMimeTypes(): array
    {
        return array_map(static fn (ImageType $type): string => $type->value, ImageType::cases());
    }

    /**
     * Un'immagine troppo piccola non è una locandina: è una miniatura presa da
     * un messaggio, e ingrandita a 1600 px si sgrana.
     *
     * Anche HEIC e AVIF sono misurati prima della decodifica completa,
     * con limiti sulle risorse del decoder e sui pixel complessivi dei frame.
     *
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    private function guardDimensions(UploadedFile $file, Closure $fail): void
    {
        $size = ImageSafety::dimensions($file->getPathname());
        if ($size === null) {
            $fail(__('validation.custom.image.invalid'));

            return;
        }
        if (config()->integer('media.max_pixels') < $size[0] * $size[1]) {
            $fail(__('validation.custom.image.too_many_pixels', ['megapixels' => (int) round(config()->integer('media.max_pixels') / 1_000_000)]));

            return;
        }

        $minWidth = config()->integer('media.min_width');
        $minHeight = config()->integer('media.min_height');

        if ($size[0] < $minWidth || $size[1] < $minHeight) {
            $fail(__('validation.custom.image.too_small', [
                'width' => $minWidth,
                'height' => $minHeight,
            ]));

            return;
        }

    }
}
