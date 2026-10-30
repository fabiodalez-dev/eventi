<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Account\RegisterUser;
use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use App\Services\Account\MobileTokenIssuer;
use App\Support\AccountLoginThrottle;
use App\Support\Api\ApiResponse;
use App\Support\SecurityLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Registrazione, accesso e uscita con token Sanctum (§13.4).
 *
 * Il login **social** non c'è: D7 lo ha rimandato perché Socialite non è
 * installabile su Laravel 13, e lo schema `users` non contiene nulla che
 * impedisca di aggiungerlo poi.
 *
 * Ogni accesso emette un token proprio, con il nome del dispositivo: chi
 * perde il telefono deve poter revocare quello e non il tablet. L'uscita
 * revoca **solo** il token con cui è stata chiesta, per la stessa ragione.
 *
 * La creazione dell'account non sta qui ma in `RegisterUser`: sito e API
 * registrano nello stesso modo, e due copie della stessa procedura sono due
 * occasioni di dimenticarsi l'email di verifica in una delle due.
 */
final class AuthController extends Controller
{
    public function __construct(private readonly MobileTokenIssuer $tokens) {}

    public function register(RegisterRequest $request, RegisterUser $register): JsonResponse
    {
        $data = $request->validated();

        $user = $register([
            'name' => is_string($data['name'] ?? null) ? $data['name'] : null,
            'first_name' => $request->validated('first_name'),
            'last_name' => $request->validated('last_name'),
            'email' => (string) $data['email'],
            'password' => (string) $data['password'],
            'marketing_opt_in' => $request->boolean('marketing_opt_in'),
        ]);

        return ApiResponse::item([
            ...$this->tokens->issue($user, $request->validated('device_name')),
            'token_type' => 'Bearer',
            'user' => UserResource::toArray($user),
            'message' => __('api.auth.registered'),
        ], status: 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', (string) $request->validated('email'))->first();

        /*
         * La stessa risposta per "email inesistente" e "password sbagliata":
         * risposte diverse direbbero a chiunque quali indirizzi sono
         * registrati. Si esegue una verifica hash anche quando l’account manca,
         * evitando il ritorno immediato del controllo cortocircuitato.
         */
        $password = (string) $request->validated('password');

        /* Il tetto per account si conta prima della verifica e si restituisce
           a password giusta (`AccountLoginThrottle`). */
        $tetto = AccountLoginThrottle::chiave('api', (string) $request->validated('email'));
        AccountLoginThrottle::prenotaOppure429($tetto);

        $valid = Hash::check($password, $user->password ?? self::hashFittizio());
        if (! $user instanceof User || ! $valid) {
            SecurityLog::accessoFallito($request->validated('email'));
            throw new ApiException(ApiErrorCode::InvalidCredentials);
        }

        AccountLoginThrottle::restituisci($tetto);
        $user->forceFill(['last_active_at' => Carbon::now()])->save();

        return ApiResponse::item([
            ...$this->tokens->issue($user, $request->validated('device_name')),
            'token_type' => 'Bearer',
            'user' => UserResource::toArray($user),
            'message' => __('api.auth.logged_in'),
        ]);
    }

    /**
     * L'hash contro cui si confronta la password quando l'account non c'è.
     *
     * Deve costare quanto quelli veri: scritto a mano con costo 10, contro il
     * 12 degli hash reali, rispondeva quattro volte più in fretta per le email
     * inesistenti, e il tempo diceva quello che il messaggio taceva. Si genera
     * con l'hasher configurato e si rigenera quando la configurazione cambia
     * (`needsRehash`). Sta in cache e non in una proprietà statica perché ogni
     * richiesta è un processo nuovo: rigenerarlo ogni volta costerebbe un
     * bcrypt in più proprio sulle email inesistenti.
     */
    private static function hashFittizio(): string
    {
        $hash = Cache::get('auth:hash-fittizio');

        if (! is_string($hash) || Hash::needsRehash($hash)) {
            $hash = Hash::make(Str::random(40));
            Cache::forever('auth:hash-fittizio', $hash);
        }

        return $hash;
    }

    /**
     * Esce da **questo** dispositivo. Revocare tutti i token a ogni uscita
     * butterebbe fuori anche il telefono di chi sta chiudendo la sessione sul
     * tablet, che non è ciò che chiede chi tocca "esci".
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new ApiException(ApiErrorCode::Unauthenticated);
        }

        /*
         * Il token c'è di sicuro: la rotta passa da `auth:sanctum`, e in
         * questo progetto Sanctum autentica solo con token personali —
         * nessuna rotta dell'API è "stateful", quindi non esiste il caso del
         * token di sessione che non si può revocare.
         */
        $user->currentAccessToken()->delete();

        return ApiResponse::item(['message' => __('api.auth.logged_out')]);
    }
}
