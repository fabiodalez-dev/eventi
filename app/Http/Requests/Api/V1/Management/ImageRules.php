<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management;

use App\Rules\RealImage;

/**
 * Il campo `image` di ogni caricamento dell'app di gestione: le stesse regole
 * del campo immagine dei pannelli (`App\Filament\Support\ImageUpload`).
 *
 * `bail` perché un file che non è un'immagine fallirebbe peso e tipo insieme,
 * e la stessa cosa detta due volte. Il tetto in kilobyte viene da
 * `config('media.max_upload_bytes')`; `RealImage` legge i byte e controlla
 * anche tipo, misure minime e superficie.
 */
final class ImageRules
{
    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'image' => ['required', 'bail', 'file', 'max:'.(int) ceil(config()->integer('media.max_upload_bytes') / 1024), new RealImage],
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return ['image' => 'immagine'];
    }
}
