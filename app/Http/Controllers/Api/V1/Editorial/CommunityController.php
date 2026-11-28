<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Editorial\Community\DeskListRequest;
use App\Http\Requests\Api\V1\Editorial\Community\ReasonRequest;
use App\Http\Requests\Api\V1\Editorial\Community\ToggleRequest;
use App\Models\CommunityProfile;
use App\Models\User;
use App\Services\Carpool\CommunitySafety;
use App\Services\Community\CommunityModeration;
use App\Services\Editorial\CommunityDesk;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;

/**
 * La pagina «Profili e post» del pannello (R-CM-01…05): le quattro sezioni
 * (profili, pubblicazioni, commenti, serate bloccate) con la ricerca, e le
 * azioni di ogni riga con il motivo obbligatorio. Le scritture passano da
 * `CommunityModeration`, la stessa azione del pannello, che ricontrolla
 * permesso (`community.manage`) e rango, scrive il registro dei passaggi
 * (`social_moderation`, `social_restriction_restored`) e quello delle
 * attività.
 *
 * Sospendere è un'azione pericolosa (decisione 4): password recente. È la
 * stessa sospensione di «Sospendi» nell'elenco degli utenti (R5).
 */
final class CommunityController extends Controller
{
    public function index(DeskListRequest $request, string $section, CommunityDesk $desk): JsonResponse
    {
        $staff = $this->staff();
        $page = $desk->query($section, $request->search())->paginate($request->perPage(), page: $request->pageNumber());
        $items = $page->getCollection();
        if ($section !== 'restrictions' && $items instanceof EloquentCollection) {
            // Il rango di chi ha scritto decide «Sospendi» e «Nascondi»: i ruoli in una query sola.
            $items->loadMissing('user.roles');
        }

        return response()->json([
            'data' => $items->map(fn (mixed $item): array => $desk->row($section, $item, $staff))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** Mette o toglie un profilo dalla selezione in evidenza (`enabled`). */
    public function feature(ToggleRequest $request, int $profile, CommunityModeration $moderation, CommunityDesk $desk): JsonResponse
    {
        $staff = $this->staff();
        $moderation->apply($staff, 'profile', $profile, $request->flag('enabled'), $request->reason());
        $fresh = CommunityProfile::query()->with('user.roles')->findOrFail($profile);

        return $this->done($desk->profileRow($fresh, $staff));
    }

    /** Sospende una persona dalla community (`users.manage`, solo chi nello staff sta sotto). Pericolosa. */
    public function suspend(ReasonRequest $request, int $user, CommunityModeration $moderation, CommunityDesk $desk): JsonResponse
    {
        return $this->suspension($request, $user, false, $moderation, $desk);
    }

    /** Riattiva una persona sospesa: la stessa regola della sospensione (`CommunityModeration::canSuspendGlobally`). */
    public function unsuspend(ReasonRequest $request, int $user, CommunityModeration $moderation, CommunityDesk $desk): JsonResponse
    {
        return $this->suspension($request, $user, true, $moderation, $desk);
    }

    /** Nasconde una pubblicazione (che blocca anche la serata) o un commento. */
    public function hide(ReasonRequest $request, string $kind, int $id, CommunityModeration $moderation, CommunityDesk $desk): JsonResponse
    {
        return $this->content($request, $kind, $id, false, $moderation, $desk);
    }

    /** Ripristina una pubblicazione (e toglie il blocco della serata) o un commento. */
    public function restore(ReasonRequest $request, string $kind, int $id, CommunityModeration $moderation, CommunityDesk $desk): JsonResponse
    {
        return $this->content($request, $kind, $id, true, $moderation, $desk);
    }

    /** Toglie la restrizione su una serata: la persona può pubblicare di nuovo. */
    public function removeRestriction(ReasonRequest $request, int $restriction, CommunityModeration $moderation): JsonResponse
    {
        $moderation->restoreRestriction($this->staff(), $restriction, $request->reason());

        return $this->done(['kind' => 'restriction', 'id' => $restriction, 'removed' => true]);
    }

    private function suspension(ReasonRequest $request, int $user, bool $enabled, CommunityModeration $moderation, CommunityDesk $desk): JsonResponse
    {
        $staff = $this->staff();
        $moderation->apply($staff, 'user', $user, $enabled, $request->reason());
        $profile = $desk->profileOf($user);

        return $this->done($profile !== null ? $desk->profileRow($profile, $staff) : ['kind' => 'user', 'user_id' => $user, 'suspended' => ! $enabled]);
    }

    private function content(ReasonRequest $request, string $kind, int $id, bool $enabled, CommunityModeration $moderation, CommunityDesk $desk): JsonResponse
    {
        $staff = $this->staff();
        $type = $kind === 'posts' ? 'post' : 'comment';
        $moderation->apply($staff, $type, $id, $enabled, $request->reason());

        return $this->done($desk->contentRow($type, $desk->content($type, $id), $staff));
    }

    /** @param  array<string, mixed>  $row */
    private function done(array $row): JsonResponse
    {
        return response()->json(['data' => $row, 'meta' => ['message' => __('admin.community_page.done')]]);
    }

    /** Chi entra nella pagina: la redazione con `community.manage`, come `Community::canAccess`. */
    private function staff(): User
    {
        $user = EditorialDesk::require()->user;
        app(CommunitySafety::class)->requireStaff($user, Permission::ManageCommunity);

        return $user;
    }
}
