<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Models\EventCollection;
use App\Models\EventOccurrence;
use App\Queries\EventOccurrenceQuery;
use App\Support\Api\ApiResponse;
use App\Support\EventUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/raccolte/{token}`: una raccolta condivisa, vista da chi ha il link.
 *
 * Senza account. Il gettone è l'unica chiave: una raccolta privata risponde
 * «non trovata» anche a chi conosce il gettone, perché richiuderla deve
 * valere subito e per tutti. Non indicizzabile: è una cosa fra amici.
 */
final class CollectionController extends Controller
{
    use InteractsWithCity;

    public function show(Request $request, string $token): View|JsonResponse
    {
        $collection = EventCollection::query()->where('token', $token)->with('user.communityProfile')->first();
        $viewer = $request->user();
        // `user` esclude gli account cestinati: la raccolta di chi si è cancellato non si apre più.
        abort_unless($collection !== null && $collection->user !== null && ($collection->is_public || ($viewer !== null && (int) $collection->user_id === (int) $viewer->getKey())), 404);

        $ids = $collection->items()->pluck('occurrence_id')->map(static fn (mixed $id): int => (int) $id)->all();
        $rows = $ids === [] ? new Collection : EventOccurrenceQuery::archiveFor($collection->city ?? $this->city())->forOccurrences($ids)->get()->keyBy('id');
        $occurrences = new Collection(collect($ids)->map(fn (int $id) => $rows->get($id))->filter()->values()->all());
        $occurrences->load(['event.venue', 'event.category', 'event.media', 'venue']);
        $notes = $collection->items()->pluck('note', 'occurrence_id');

        if ($request->is('api/*')) {
            return ApiResponse::item([
                'name' => $collection->name,
                'description' => $collection->description,
                'owner' => $collection->ownerLabel(),
                'items' => $occurrences->map(fn (EventOccurrence $occurrence): array => [
                    'occurrence_id' => $occurrence->getKey(),
                    'title' => $occurrence->event?->title,
                    'starts_at' => $occurrence->starts_at->toIso8601String(),
                    'venue' => $occurrence->effectiveVenue()?->name,
                    'url' => EventUrl::occurrence($occurrence),
                    'note' => $notes[$occurrence->getKey()] ?? null,
                ])->all(),
            ]);
        }

        return view('collections.show', [
            'collection' => $collection,
            'occurrences' => $occurrences,
            'notes' => $notes,
            'isOwner' => $viewer !== null && (int) $collection->user_id === (int) $viewer->getKey(),
            'meta' => new PageMeta(title: $collection->name, heading: $collection->name, description: $collection->description ?? '', indexable: false),
        ]);
    }
}
