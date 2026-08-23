<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\MagicLinkRequest;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * `POST /v1/auth/magic-link` (§15.2): l'accesso senza password, che il piano
 * raccomanda come via primaria per un uso saltuario come questo.
 *
 * Il collegamento che parte è **quello del sito**, firmato e a scadenza: apre
 * una sessione nel browser. Non consegna un token dell'API perché un token
 * consegnato attraverso un indirizzo web finirebbe nella cronologia del
 * browser, nel referer e nei log del proxy — cioè in tre posti dove una
 * credenziale non deve stare. Un'app che vuole un token proprio ha la
 * registrazione e l'accesso con password; il collegamento serve a chi la
 * password non ce l'ha.
 *
 * La risposta è sempre la stessa, che l'indirizzo esista o no.
 */
final class MagicLinkController extends Controller
{
    public function __invoke(MagicLinkRequest $request): JsonResponse
    {
        $user = User::query()->where('email', (string) $request->validated('email'))->first();

        $user?->notify(new MagicLoginLink);

        return ApiResponse::item(['message' => __('account.api.magic_link_sent')]);
    }
}
