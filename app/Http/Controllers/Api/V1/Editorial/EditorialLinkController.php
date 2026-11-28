<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Venue;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Gli indirizzi del pannello che portano uno slug (`/admin/events/{slug}`,
 * `/admin/venues/{slug}/edit`): l'app li riceve da un avviso o da un
 * collegamento interno e ha bisogno dell'id per aprire la scheda nativa.
 * Il record si trova come lo trova il pannello (per slug, il primo; anche
 * nel cestino) e lo apre solo chi lo può vedere.
 */
final class EditorialLinkController extends Controller
{
    /** `type` è `events` o `venues`; risponde `{type, id, title}`. */
    public function resolve(string $type, string $slug): JsonResponse
    {
        $desk = EditorialDesk::require();
        $record = match ($type) {
            'events' => Event::withTrashed()->where('slug', $slug)->orderBy('id')->first(),
            'venues' => Venue::withTrashed()->where('slug', $slug)->orderBy('id')->first(),
            default => null,
        };
        abort_if($record === null, 404);
        Gate::forUser($desk->user)->authorize('view', $record);

        return response()->json(['data' => [
            'type' => $type,
            'id' => (int) $record->getKey(),
            'title' => (string) ($record instanceof Event ? $record->title : $record->name),
        ]]);
    }
}
