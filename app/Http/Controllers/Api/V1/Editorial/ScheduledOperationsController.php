<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Editorial\EditorialPermissions;
use App\Services\Editorial\EditorialScheduledOperations;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Http\JsonResponse;

/**
 * «Cron e programmazioni» (R-SY-07), di sola lettura come la pagina del
 * pannello. La pagina controlla il ruolo (`canAccess`: admin o super admin),
 * non una policy: qui la stessa domanda è `system_view` di
 * `EditorialPermissions`. Il moderatore riceve 403.
 */
final class ScheduledOperationsController extends Controller
{
    public function show(EditorialPermissions $permissions, EditorialScheduledOperations $operations): JsonResponse
    {
        if (! $permissions->allows(EditorialDesk::require()->user, 'system_view')) {
            throw new ApiException(ApiErrorCode::Forbidden);
        }

        return response()->json(['data' => $operations->payload()]);
    }
}
