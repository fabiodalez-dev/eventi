<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Editorial\EditorialBell;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * La campanella della redazione nell'app (R-DB-11): le notifiche che il
 * pannello mostra nella sua campanella, con le stesse operazioni (letta, da
 * leggere, togli, tutte lette, svuota). Ognuno vede solo le sue.
 */
final class EditorialNotificationController extends Controller
{
    public function __construct(private readonly EditorialBell $bell) {}

    /** Le notifiche della campanella, dalla più recente, a pagine di 50; `meta.unread` le non lette. */
    public function index(Request $request): JsonResponse
    {
        $desk = EditorialDesk::require();
        $timezone = $desk->timezone();
        $page = $this->bell->query($desk->user)->latest()->latest('id')
            ->paginate(EditorialBell::PER_PAGE, page: max(1, $request->integer('page', 1)));

        return response()->json([
            'data' => $page->getCollection()->map(fn (DatabaseNotification $n): array => EditorialBell::row($n, $timezone))->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'unread' => $this->bell->unread($desk->user),
            ],
        ]);
    }

    /** «Segna come letta». */
    public function read(string $notification): JsonResponse
    {
        return $this->change($notification, fn ($user) => $this->bell->markRead($user, $notification), 'read');
    }

    /** «Segna come da leggere». */
    public function unread(string $notification): JsonResponse
    {
        return $this->change($notification, fn ($user) => $this->bell->markUnread($user, $notification), 'unread');
    }

    /** La «x» della notifica: la toglie dall'archivio, come il pannello. */
    public function remove(string $notification): JsonResponse
    {
        $desk = EditorialDesk::require();
        abort_unless($this->bell->remove($desk->user, $notification), 404);

        return response()->json(['data' => null, 'meta' => ['message' => __('editorial-calendar.removed'), 'unread' => $this->bell->unread($desk->user)]]);
    }

    /** «Segna tutte come lette». */
    public function readAll(): JsonResponse
    {
        $desk = EditorialDesk::require();
        $changed = $this->bell->markAllRead($desk->user);

        return response()->json(['data' => ['changed' => $changed], 'meta' => ['message' => __('editorial-calendar.all_read'), 'unread' => 0]]);
    }

    /** «Svuota»: toglie tutte le notifiche della campanella (non gli altri avvisi dell'account). */
    public function clear(): JsonResponse
    {
        $desk = EditorialDesk::require();
        $removed = $this->bell->clear($desk->user);

        return response()->json(['data' => ['removed' => $removed], 'meta' => ['message' => __('editorial-calendar.cleared'), 'unread' => 0]]);
    }

    /** @param  callable(User): bool  $action */
    private function change(string $id, callable $action, string $message): JsonResponse
    {
        $desk = EditorialDesk::require();
        $found = $this->bell->find($desk->user, $id);
        abort_if($found === null, 404);
        $action($desk->user);
        $fresh = $this->bell->find($desk->user, $id);
        abort_if($fresh === null, 404);

        return response()->json([
            'data' => EditorialBell::row($fresh, $desk->timezone()),
            'meta' => ['message' => __('editorial-calendar.'.$message), 'unread' => $this->bell->unread($desk->user)],
        ]);
    }
}
