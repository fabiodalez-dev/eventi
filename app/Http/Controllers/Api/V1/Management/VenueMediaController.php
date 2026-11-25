<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Management\VenueMediaRequest;
use App\Services\Management\ManagementImages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

/**
 * Logo, copertina e galleria del locale, dalle stesse raccolte media della
 * pagina «Profilo del locale».
 */
final class VenueMediaController extends Controller
{
    /** Le immagini del locale: `logo`, `cover`, `gallery[]` (con `id`). */
    public function index(VenueMediaRequest $request, ManagementImages $images): JsonResponse
    {
        return response()->json(['data' => $images->venueImages($request->venue())]);
    }

    /** Carica o sostituisce il logo o la copertina (campo multipart `image`). */
    public function store(VenueMediaRequest $request, ManagementImages $images): JsonResponse
    {
        $collection = $request->collection();
        $file = $request->file('image');
        abort_unless($file instanceof UploadedFile, 422);

        return response()->json(['data' => ['collection' => $collection, 'image' => $images->replaceVenueImage($request->venue(), $collection, $file)]]);
    }

    /** Toglie il logo o la copertina. */
    public function destroy(VenueMediaRequest $request, ManagementImages $images): JsonResponse
    {
        $collection = $request->collection();
        $images->removeVenueImage($request->venue(), $collection);

        return response()->json(['data' => ['collection' => $collection, 'image' => null]]);
    }

    /** Aggiunge una foto alla galleria (campo multipart `image`), fino a 30. */
    public function storeGallery(VenueMediaRequest $request, ManagementImages $images): JsonResponse
    {
        $file = $request->file('image');
        abort_unless($file instanceof UploadedFile, 422);

        return response()->json(['data' => ['gallery' => $images->addToGallery($request->venue(), $file)]], 201);
    }

    /** Toglie una foto dalla galleria. */
    public function destroyGallery(VenueMediaRequest $request, ManagementImages $images): JsonResponse
    {
        return response()->json(['data' => ['gallery' => $images->removeFromGallery($request->venue(), (int) $request->route('media'))]]);
    }

    /** Il nuovo ordine della galleria: `ids` con tutte e sole le foto. */
    public function orderGallery(VenueMediaRequest $request, ManagementImages $images): JsonResponse
    {
        return response()->json(['data' => ['gallery' => $images->reorderGallery($request->venue(), $request->ids())]]);
    }
}
