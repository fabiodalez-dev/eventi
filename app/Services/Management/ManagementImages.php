<?php

declare(strict_types=1);

namespace App\Services\Management;

use App\Http\Resources\V1\PosterResource;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Services\Events\EventReviewRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Le immagini caricate dall'app di gestione: locandina dell'evento, logo,
 * copertina e galleria del locale.
 *
 * Vanno nelle raccolte media che usano già i pannelli, nessuna nuova:
 * `poster`, `logo` e `cover` sono `singleFile()`, quindi il file nuovo prende
 * il posto del vecchio e la libreria cancella dal disco quello sostituito.
 * Riduzione dell'originale, pulizia EXIF e varianti partono da sole, perché
 * sono agganciate alla libreria (`ProcessImageMedia`): niente conversioni
 * nuove, niente copie in più sulla quota.
 *
 * La validazione del file (tipo letto dai byte, peso, misure, superficie) la
 * fa `App\Rules\RealImage` nella richiesta, prima di arrivare qui.
 */
final class ManagementImages
{
    public function __construct(private readonly EventReviewRule $review) {}

    /**
     * La locandina nuova. Su un evento pubblicato è contenuto nuovo come un
     * testo riscritto: se la regola lo chiede l'evento torna in revisione.
     *
     * @return array{poster: array<string, mixed>|null, status: string, sent_to_review: bool}
     */
    public function replacePoster(Event $event, Venue|Organizer $subject, User $user, UploadedFile $file): array
    {
        $event->addMedia($file)->toMediaCollection('poster');
        $sent = $this->review->applyAfterNewContent($event, $subject, $user);
        if (! $sent) {
            // Il salvataggio aggiorna la versione dei contenuti della città, come il pannello.
            $event->touch();
        }

        return $this->poster($event->refresh(), $sent);
    }

    /**
     * Togliere la locandina non porta niente di nuovo in pagina: non rimanda
     * in revisione (la stessa scelta del pannello).
     *
     * @return array{poster: array<string, mixed>|null, status: string, sent_to_review: bool}
     */
    public function removePoster(Event $event): array
    {
        $event->clearMediaCollection('poster');
        $event->touch();

        return $this->poster($event->refresh(), false);
    }

    /** @return array<string, mixed> */
    public function replaceVenueImage(Venue $venue, string $collection, UploadedFile $file): array
    {
        $media = $venue->addMedia($file)->toMediaCollection($collection);
        $venue->touch();

        return PosterResource::media($media->refresh());
    }

    public function removeVenueImage(Venue $venue, string $collection): void
    {
        $venue->clearMediaCollection($collection);
        $venue->touch();
    }

    /**
     * Una foto in più nella galleria, entro il tetto del pannello. Il locale
     * resta bloccato finché la foto non è salvata: due caricamenti insieme
     * dalla trentesima non ne fanno trentuno.
     *
     * @return list<array<string, mixed>>
     */
    public function addToGallery(Venue $venue, UploadedFile $file): array
    {
        DB::transaction(function () use ($venue, $file): void {
            Venue::query()->whereKey($venue->getKey())->lockForUpdate()->first();
            $count = Media::query()->where('model_type', $venue->getMorphClass())->where('model_id', $venue->getKey())
                ->where('collection_name', 'gallery')->count();
            if ($count >= ManagementOptions::GALLERY_MAX) {
                throw ValidationException::withMessages(['image' => 'La galleria ha già '.ManagementOptions::GALLERY_MAX.' foto: togline una prima di aggiungerne un’altra.']);
            }
            $venue->addMedia($file)->toMediaCollection('gallery');
        });
        $venue->touch();

        return $this->gallery($venue);
    }

    /** @return list<array<string, mixed>> */
    public function removeFromGallery(Venue $venue, int $mediaId): array
    {
        $this->galleryMedia($venue, $mediaId)->delete();
        $venue->touch();

        return $this->gallery($venue);
    }

    /**
     * Il nuovo ordine della galleria: `ids` deve contenere tutte e sole le
     * foto del locale, come il trascinamento del pannello.
     *
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    public function reorderGallery(Venue $venue, array $ids): array
    {
        $current = $venue->getMedia('gallery')->map(fn (Media $media): int => (int) $media->getKey())->sort()->values()->all();
        $wanted = collect($ids)->map(fn (int $id): int => $id)->sort()->values()->all();
        if ($current !== $wanted) {
            throw ValidationException::withMessages(['ids' => 'L’ordine deve elencare tutte e sole le foto della galleria.']);
        }

        Media::setNewOrder($ids);
        $venue->touch();

        return $this->gallery($venue->refresh());
    }

    /** @return array{logo: array<string, mixed>|null, cover: array<string, mixed>|null, gallery: list<array<string, mixed>>} */
    public function venueImages(Venue $venue): array
    {
        $logo = $venue->getFirstMedia('logo');
        $cover = $venue->getFirstMedia('cover');

        return [
            'logo' => $logo instanceof Media ? PosterResource::media($logo) : null,
            'cover' => $cover instanceof Media ? PosterResource::media($cover) : null,
            'gallery' => $this->gallery($venue),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function gallery(Venue $venue): array
    {
        return $venue->load('media')->getMedia('gallery')
            ->map(fn (Media $media): array => ['id' => (int) $media->getKey(), ...PosterResource::media($media)])
            ->values()->all();
    }

    private function galleryMedia(Venue $venue, int $mediaId): Media
    {
        $media = Media::query()->whereKey($mediaId)->where('model_type', $venue->getMorphClass())
            ->where('model_id', $venue->getKey())->where('collection_name', 'gallery')->first();
        abort_unless($media instanceof Media, 404);

        return $media;
    }

    /** @return array{poster: array<string, mixed>|null, status: string, sent_to_review: bool} */
    private function poster(Event $event, bool $sent): array
    {
        return [
            'poster' => PosterResource::toArray($event->load('media')),
            'status' => $event->status->value,
            'sent_to_review' => $sent,
        ];
    }
}
