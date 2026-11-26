<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Management\ImageRules;
use App\Models\User;
use App\Models\Venue;
use App\Services\Management\ManagementImages;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * Logo, copertina e galleria di un locale dalla redazione (R-VN-16): le
 * stesse raccolte media e le stesse regole (`ManagementImages`, `ImageRules`)
 * della pagina «Profilo del locale» e della scheda Identità del modulo del
 * pannello. Le cambia chi può modificare il locale (`VenuePolicy::update`).
 */
final class VenueMediaController extends Controller
{
    /** Le immagini del locale: `logo`, `cover`, `gallery[]` (con `id`). */
    public function index(Request $request, ManagementImages $images, int $venue): JsonResponse
    {
        return response()->json(['data' => $images->venueImages($this->venue($request, $venue))]);
    }

    /** Carica o sostituisce il logo o la copertina (campo multipart `image`). */
    public function store(Request $request, ManagementImages $images, int $venue, string $collection): JsonResponse
    {
        $record = $this->venue($request, $venue);
        $file = $this->image($request);

        return response()->json(['data' => ['collection' => $collection, 'image' => $images->replaceVenueImage($record, $collection, $file)]]);
    }

    /** Toglie il logo o la copertina. */
    public function remove(Request $request, ManagementImages $images, int $venue, string $collection): JsonResponse
    {
        $images->removeVenueImage($this->venue($request, $venue), $collection);

        return response()->json(['data' => ['collection' => $collection, 'image' => null]]);
    }

    /** Aggiunge una foto alla galleria (campo multipart `image`), fino a 30. */
    public function storeGallery(Request $request, ManagementImages $images, int $venue): JsonResponse
    {
        $record = $this->venue($request, $venue);

        return response()->json(['data' => ['gallery' => $images->addToGallery($record, $this->image($request))]], 201);
    }

    /** Toglie una foto dalla galleria. */
    public function removeGallery(Request $request, ManagementImages $images, int $venue, int $media): JsonResponse
    {
        return response()->json(['data' => ['gallery' => $images->removeFromGallery($this->venue($request, $venue), $media)]]);
    }

    /** Il nuovo ordine della galleria: `ids` con tutte e sole le foto. */
    public function orderGallery(Request $request, ManagementImages $images, int $venue): JsonResponse
    {
        $record = $this->venue($request, $venue);
        $data = $request->validate([
            'ids' => ['required', 'array', 'list', 'max:100'],
            'ids.*' => ['required', 'integer', 'distinct', 'min:1'],
        ]);

        return response()->json(['data' => ['gallery' => $images->reorderGallery($record, array_values(array_map('intval', (array) $data['ids'])))]]);
    }

    private function venue(Request $request, int $id): Venue
    {
        EditorialDesk::require();
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $venue = Venue::query()->with('city')->find($id);
        if (! $venue instanceof Venue) {
            throw new ApiException(ApiErrorCode::NotFound);
        }
        Gate::forUser($user)->authorize('update', $venue);

        return $venue;
    }

    private function image(Request $request): UploadedFile
    {
        $request->validate(ImageRules::rules(), [], ImageRules::attributes());
        $file = $request->file('image');
        abort_unless($file instanceof UploadedFile, 422);

        return $file;
    }
}
