<?php

declare(strict_types=1);

namespace App\Services\Editorial;

use App\Http\Resources\V1\PosterResource;
use App\Models\Event;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Locandina e galleria di un evento dal modulo della redazione (i campi
 * «Locandina» e «Galleria» di `EventResource::form`).
 *
 * Le raccolte sono quelle del pannello: `poster` (un file solo: il nuovo
 * prende il posto del vecchio) e `gallery` (più foto, in ordine). Riduzione,
 * pulizia EXIF e varianti partono da sole con la libreria; il controllo del
 * file (`RealImage`) lo fa la richiesta. La redazione non passa dalla
 * revisione: una locandina nuova non rimanda l'evento alla redazione.
 */
final class EditorialEventMedia
{
    /** @return array{poster: array<string, mixed>|null, gallery: list<array<string, mixed>>} */
    public function replacePoster(Event $event, UploadedFile $file): array
    {
        $event->addMedia($file)->toMediaCollection('poster');
        $event->touch();

        return $this->images($event);
    }

    /** @return array{poster: array<string, mixed>|null, gallery: list<array<string, mixed>>} */
    public function removePoster(Event $event): array
    {
        $event->clearMediaCollection('poster');
        $event->touch();

        return $this->images($event);
    }

    /** @return array{poster: array<string, mixed>|null, gallery: list<array<string, mixed>>} */
    public function addToGallery(Event $event, UploadedFile $file): array
    {
        $event->addMedia($file)->toMediaCollection('gallery');
        $event->touch();

        return $this->images($event);
    }

    /** @return array{poster: array<string, mixed>|null, gallery: list<array<string, mixed>>} */
    public function removeFromGallery(Event $event, int $mediaId): array
    {
        $media = Media::query()->whereKey($mediaId)->where('model_type', $event->getMorphClass())
            ->where('model_id', $event->getKey())->where('collection_name', 'gallery')->first();
        abort_unless($media instanceof Media, 404);
        $media->delete();
        $event->touch();

        return $this->images($event);
    }

    /**
     * Il nuovo ordine della galleria: tutte e sole le foto dell'evento, come
     * il trascinamento del pannello.
     *
     * @param  list<int>  $ids
     * @return array{poster: array<string, mixed>|null, gallery: list<array<string, mixed>>}
     */
    public function reorderGallery(Event $event, array $ids): array
    {
        $current = $event->getMedia('gallery')->map(fn (Media $media): int => (int) $media->getKey())->sort()->values()->all();
        $wanted = collect($ids)->map(fn (int $id): int => $id)->sort()->values()->all();
        if ($current !== $wanted) {
            throw ValidationException::withMessages(['ids' => 'L’ordine deve elencare tutte e sole le foto della galleria.']);
        }
        Media::setNewOrder($ids);
        $event->touch();

        return $this->images($event->refresh());
    }

    /** @return array{poster: array<string, mixed>|null, gallery: list<array<string, mixed>>} */
    public function images(Event $event): array
    {
        $event->load('media');

        return [
            'poster' => PosterResource::toArray($event),
            'gallery' => $event->getMedia('gallery')->map(fn (Media $media): array => ['id' => (int) $media->getKey(), ...PosterResource::media($media)])->values()->all(),
        ];
    }
}
