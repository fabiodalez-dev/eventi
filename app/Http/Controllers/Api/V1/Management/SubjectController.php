<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Management\ManagementSubjects;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * I locali e le organizzazioni che la persona gestisce, con ruolo e permessi
 * per area: la prima cosa che l'app di gestione chiede.
 */
final class SubjectController extends Controller
{
    /**
     * Elenco dei soggetti di gestione.
     *
     * `venues[]` sono i locali con appartenenza accettata e senza
     * provvedimento, `organizers[]` le organizzazioni attive gestite;
     * `checkin_only` è vero per chi fa solo il controllo ingressi.
     * `permissions` ha una chiave per area, calcolata dalle policy del sito.
     */
    public function index(Request $request, ManagementSubjects $subjects): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json(['data' => $subjects->payload($user)]);
    }
}
