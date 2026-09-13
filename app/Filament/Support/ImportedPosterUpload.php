<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Venue\Resources\Events\Pages\CreateEvent;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Livewire\Attributes\Renderless;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/** A server-imported temporary image needs a preview before a record exists. */
class ImportedPosterUpload extends SpatieMediaLibraryFileUpload
{
    public function getLivewireKey(): ?string
    {
        $page = $this->getLivewire();

        return parent::getLivewireKey().'.'.($page instanceof CreateEvent ? $page->facebookPhotoRevision : 0);
    }

    #[ExposedLivewireMethod]
    #[Renderless]
    public function getUploadedFiles(): ?array
    {
        $files = parent::getUploadedFiles() ?? [];
        foreach ($this->getRawState() ?? [] as $key => $file) {
            if ($file instanceof TemporaryUploadedFile) {
                $files[$key] = [
                    'name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'type' => $file->getMimeType(),
                    'url' => $file->temporaryUrl(),
                ];
            }
        }

        return $files;
    }
}
