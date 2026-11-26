<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Editorial\Users\RenameHandleRequest;
use App\Models\User;
use App\Services\Community\CommunityModeration;
use App\Services\Community\WhatsappVerification;
use App\Services\Users\StaffUserPayload;
use App\Services\Users\UserDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Le azioni sulla community dal menu della riga dell'elenco «Utenti»
 * (R-US-08…11): sospendi e riattiva, cambia il nome utente, revoca la
 * verifica WhatsApp, storico dei tentativi. Chiamano le stesse azioni del
 * pannello, che controllano permesso e rango (`CommunityModeration`,
 * `WhatsappVerification`) e scrivono i registri.
 */
final class UserCommunityController extends Controller
{
    /**
     * Sospende dalla community. Come il pannello: conferma senza motivo
     * scritto (vale quello predefinito dell'azione) e solo verso chi sta
     * sotto nello staff.
     */
    public function suspend(Request $request, User $user, CommunityModeration $moderation, StaffUserPayload $payload): JsonResponse
    {
        $staff = $this->staff($request);
        abort_if($user->trashed(), 404);
        $moderation->apply($staff, 'user', $user->id, enabled: false);

        return $this->detail($staff, $user, $payload, __('users.app.suspended'));
    }

    /** Riattiva: chiunque abbia `community.manage`, anche verso chi sta sopra (come il pannello). */
    public function unsuspend(Request $request, User $user, CommunityModeration $moderation, StaffUserPayload $payload): JsonResponse
    {
        $staff = $this->staff($request);
        abort_if($user->trashed(), 404);
        $moderation->apply($staff, 'user', $user->id, enabled: true);

        return $this->detail($staff, $user, $payload, __('users.app.unsuspended'));
    }

    /** Cambia il nome utente senza l'attesa del titolare (D64), con il motivo. */
    public function handle(RenameHandleRequest $request, User $user, CommunityModeration $moderation, StaffUserPayload $payload): JsonResponse
    {
        $staff = $this->staff($request);
        abort_if($user->trashed(), 404);
        $profile = $user->communityProfile;
        abort_if($profile === null, 404);
        $moderation->renameHandle($staff, (int) $profile->getKey(), (string) $request->validated('handle'), (string) $request->validated('reason'));

        return $this->detail($staff, $user, $payload, __('community.handle_renamed'));
    }

    /** Revoca la verifica WhatsApp: solo admin e super admin. */
    public function revokeWhatsapp(Request $request, User $user, WhatsappVerification $verification, StaffUserPayload $payload): JsonResponse
    {
        $staff = $this->staff($request);
        abort_unless($staff->hasAnyRole([UserRole::Admin->value, UserRole::SuperAdmin->value]), 403);
        abort_if($user->whatsapp_verified_at === null, 404);
        $verification->revoke($user, $staff);

        return $this->detail($staff, $user, $payload, __('users.app.whatsapp_revoked'));
    }

    /** Gli ultimi trenta tentativi di verifica: tutta la redazione. */
    public function whatsappHistory(Request $request, User $user, StaffUserPayload $payload): JsonResponse
    {
        $staff = $this->staff($request);
        Gate::forUser($staff)->authorize('view', $user);

        return response()->json(['data' => $payload->whatsappHistory($user)]);
    }

    private function detail(User $staff, User $user, StaffUserPayload $payload, string $message): JsonResponse
    {
        $fresh = User::withTrashed()->with(UserDirectory::ROW_RELATIONS)->findOrFail($user->id);

        return response()->json(['data' => $payload->detail($staff, $fresh), 'meta' => ['message' => $message]]);
    }

    private function staff(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
