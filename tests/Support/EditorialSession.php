<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\UserRole;
use App\Http\Middleware\RequireEditorialUnlock;
use App\Http\Middleware\RequireRecentPassword;
use App\Http\Middleware\ResolveEditorialDesk;
use App\Http\Middleware\TicketingPrivacy;
use App\Models\EditorialUnlock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;

/**
 * Le chiavi della redazione per i test dei pacchetti: un token vero
 * dell'app (lo sblocco si lega al token, `Sanctum::actingAs` non basta) e,
 * se serve, la riga di sblocco già scritta.
 *
 *     $token = EditorialSession::token($moderatore);                 // sbloccato, password recente
 *     $token = EditorialSession::token($admin, passwordMinutesAgo: 20); // sbloccato, password da riscrivere (423 PASSWORD_REQUIRED)
 *     $token = EditorialSession::token($admin, unlocked: false);     // bloccato (423 EDITORIAL_LOCKED)
 *     EditorialSession::as($this, $token)->getJson('/api/v1/editorial/…');
 */
final class EditorialSession
{
    public static function token(User $user, bool $unlocked = true, int $unlockedHoursAgo = 0, int $passwordMinutesAgo = 0): string
    {
        $new = $user->createToken('test-editorial');
        if ($unlocked) {
            $at = CarbonImmutable::now()->subMinutes($passwordMinutesAgo)->subHours($unlockedHoursAgo);
            EditorialUnlock::query()->create([
                'token_id' => $new->accessToken->getKey(),
                'user_id' => $user->getKey(),
                'unlocked_at' => $at,
                'confirmed_until' => $at->addMinutes(15),
            ]);
        }

        return $new->plainTextToken;
    }

    /**
     * Le richieste successive con questo token. Dimentica le guardie prima:
     * nei test la guardia di Sanctum ricorda l'utente della richiesta di
     * prima, e un secondo token passerebbe per il primo utente.
     */
    public static function as(mixed $test, string $token): mixed
    {
        app('auth')->forgetGuards();

        return $test->withToken($token);
    }

    /** Una persona con un ruolo di piattaforma (i ruoli devono essere già seminati). */
    public static function user(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    /**
     * Due rotte di prova con lo stesso impianto di quelle dei pacchetti, per
     * provare sblocco e password recente prima che ci siano rotte vere:
     * `GET /api/v1/editorial/_test/locked` e `POST /api/v1/editorial/_test/destroy`.
     */
    public static function registerProbeRoutes(): void
    {
        Route::prefix('api/v1/editorial/_test')
            ->middleware(['api', 'auth:sanctum', TicketingPrivacy::class, ResolveEditorialDesk::class, RequireEditorialUnlock::class])
            ->name('api.v1.editorial._test.')
            ->group(function (): void {
                Route::get('/locked', fn () => response()->json(['data' => ['ok' => true]]))->name('locked');
                Route::post('/destroy', fn () => response()->json(['data' => ['ok' => true]]))->middleware(RequireRecentPassword::class)->name('destroy');
            });
    }
}
