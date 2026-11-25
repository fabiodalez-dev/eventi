<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Controller;
use App\Services\Management\ManagementOptions;
use Illuminate\Http\JsonResponse;

/**
 * Gli elenchi chiusi e i tetti dei moduli di gestione, letti dal server così
 * che l'app non li scriva a mano.
 */
final class OptionsController extends Controller
{
    /** Elenchi e tetti dei moduli di gestione. */
    public function __invoke(ManagementOptions $options): JsonResponse
    {
        return response()->json(['data' => $options->payload()]);
    }
}
