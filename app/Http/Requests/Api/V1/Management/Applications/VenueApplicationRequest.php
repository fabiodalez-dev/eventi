<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Applications;

use App\Support\VenueApplications\ApplicationRules;
use Illuminate\Http\UploadedFile;

/**
 * «Registra il tuo locale» dall'app (W-AP-01): la scheda pubblica, l'immagine
 * facoltativa (multipart, campo `image`, fino a 5 MB e almeno 400×300) e il
 * referente.
 */
final class VenueApplicationRequest extends ApplicationRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [...ApplicationRules::venue(), ...ApplicationRules::contact()];
    }

    public function venueImage(): ?UploadedFile
    {
        $image = $this->file('image');

        return $image instanceof UploadedFile ? $image : null;
    }
}
