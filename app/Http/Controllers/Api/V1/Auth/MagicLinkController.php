<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\MagicLinkRequest;
use App\Models\MobileAuthChallenge;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use App\Support\Api\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

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

        if ($user instanceof User) {
            $rawToken = Str::random(64);
            $minutes = config()->integer('account.magic_link_minutes');

            /* Un secondo invio rende inutilizzabile il precedente: oltre a
               limitare la tabella, elimina l'ambiguita' di avere piu' link
               validi per lo stesso account nello stesso momento. */
            MobileAuthChallenge::query()->where('user_id', $user->getKey())->delete();

            MobileAuthChallenge::query()->create([
                'user_id' => $user->getKey(),
                'token_hash' => hash('sha256', $rawToken),
                'expires_at' => CarbonImmutable::now()->addMinutes($minutes),
            ]);

            $template = config('api.auth.magic_link_url');
            $url = is_string($template) && $template !== ''
                ? str_replace('{token}', $rawToken, $template)
                : url('/app/auth/magic?token='.rawurlencode($rawToken));

            $user->notify(new MagicLoginLink($url));
        }

        return ApiResponse::item(['message' => __('account.api.magic_link_sent')]);
    }
}
