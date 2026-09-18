<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\DTOs\WhatsappSendResult;
use App\Enums\KapsoOutcome;
use App\Enums\WhatsappChallengeStatus;
use App\Enums\WhatsappDelivery;
use App\Models\User;
use App\Models\WhatsappChallenge;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Propaganistas\LaravelPhone\PhoneNumber;

final class WhatsappVerification
{
    private const IP_DAILY_LIMIT = 10;

    public function __construct(private readonly KapsoClient $client) {}

    public function request(User $user, #[\SensitiveParameter] string $input, string $ip, WhatsappDelivery $delivery = WhatsappDelivery::CopyCode): WhatsappSendResult
    {
        abort_unless($user->hasVerifiedEmail(), 403, __('community.email_required'));
        if (! $this->client->available()) {
            throw ValidationException::withMessages(['phone' => __('community.whatsapp.unavailable')]);
        }
        $phone = (new PhoneNumber($input))->formatE164();
        $fingerprint = $this->fingerprint($phone);
        $code = (string) random_int(100000, 999999);
        $ipKey = 'wa-ip:'.hash('sha256', $ip);
        $challenge = Cache::lock('community-whatsapp-requests', 15)->block(3, function () use ($user, $phone, $fingerprint, $code, $ipKey): WhatsappChallenge {
            return DB::transaction(function () use ($user, $phone, $fingerprint, $code, $ipKey): WhatsappChallenge {
                $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                abort_unless($locked->hasVerifiedEmail() && $locked->community_suspended_at === null, 403);
                $recent = WhatsappChallenge::query()->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('phone_hash', $fingerprint));
                // Un invio rifiutato da Kapso non ha raggiunto nessuno: non consuma i tetti
                // orario e giornaliero, ma resta nel minuto di attesa contro i tentativi a raffica.
                $delivered = (clone $recent)->where('status', '!=', WhatsappChallengeStatus::Failed->value);
                if ((clone $recent)->where('created_at', '>', now()->subMinute())->exists()
                    || (clone $delivered)->where('created_at', '>', now()->subHour())->count() >= 3
                    || (clone $delivered)->where('created_at', '>', now()->subDay())->count() >= config('community.daily_send_limit')) {
                    throw ValidationException::withMessages(['phone' => __('community.whatsapp.rate_limit')]);
                }
                if (RateLimiter::tooManyAttempts($ipKey, self::IP_DAILY_LIMIT) || RateLimiter::tooManyAttempts('wa-global', (int) config('community.global_daily_send_limit'))) {
                    throw ValidationException::withMessages(['phone' => __('community.whatsapp.rate_limit')]);
                }
                // Il tentativo si conta per IP prima di dire se il numero è libero: altrimenti
                // la risposta phone_unavailable diventa un oracolo gratuito sui numeri iscritti.
                RateLimiter::hit($ipKey, 86400);
                if (User::withTrashed()->where('whatsapp_phone_hash', $fingerprint)->whereKeyNot($user->id)->exists()) {
                    throw ValidationException::withMessages(['phone' => __('community.whatsapp.phone_unavailable')]);
                }
                // Il budget globale paga solo i messaggi che partono davvero.
                RateLimiter::hit('wa-global', 86400);

                return WhatsappChallenge::query()->create([
                    'id' => (string) Str::uuid(), 'user_id' => $user->id, 'phone' => $phone,
                    'phone_hash' => $fingerprint, 'code_hash' => Hash::make($code),
                    'expires_at' => now()->addMinutes(config('community.code_minutes')),
                ]);
            });
        });
        // L'invio sta fuori da lock e transazione: Kapso può metterci secondi.
        $outcome = $this->client->send($phone, $code, $delivery);
        if ($outcome === KapsoOutcome::Rejected) {
            // Niente è partito: il codice precedente resta valido e i contatori tornano indietro.
            $challenge->update(['status' => WhatsappChallengeStatus::Failed, 'consumed_at' => now()]);
            foreach ([$ipKey, 'wa-global'] as $key) {
                if ((int) RateLimiter::attempts($key) > 0) {
                    RateLimiter::decrement($key, 86400);
                }
            }
            throw ValidationException::withMessages(['phone' => __('community.whatsapp.send_failed')]);
        }
        // Anche un esito incerto può aver consegnato il codice nuovo: da qui vale solo quello.
        $challenge->update(['status' => WhatsappChallengeStatus::Sent]);
        WhatsappChallenge::query()->where('user_id', $user->id)->whereKeyNot($challenge->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);

        return new WhatsappSendResult($challenge, $outcome);
    }

    public function confirm(User $user, string $id, #[\SensitiveParameter] string $code): void
    {
        try {
            $success = DB::transaction(function () use ($user, $id, $code): bool {
                $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                abort_unless($locked->hasVerifiedEmail() && $locked->community_suspended_at === null, 403);
                $challenge = WhatsappChallenge::query()->whereKey($id)->where('user_id', $user->id)->lockForUpdate()->first();
                if ($challenge === null || $challenge->consumed_at !== null || $challenge->expires_at->lessThanOrEqualTo(now())
                    || $challenge->status !== WhatsappChallengeStatus::Sent || $challenge->attempts >= config('community.max_attempts')) {
                    return false;
                }
                $challenge->increment('attempts');
                if (! Hash::check($code, $challenge->code_hash)) {
                    return false;
                }
                if (User::withTrashed()->where('whatsapp_phone_hash', $challenge->phone_hash)->whereKeyNot($locked->id)->exists()) {
                    return false;
                }
                $locked->forceFill(['whatsapp_phone' => $challenge->phone, 'whatsapp_phone_hash' => $challenge->phone_hash,
                    'whatsapp_verified_at' => now(), 'whatsapp_prompted_at' => now()])->save();
                $challenge->update(['consumed_at' => now()]);
                activity('community')->causedBy($locked)->event('whatsapp_verified')->log('whatsapp_verified');

                return true;
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) !== 1062) {
                throw $exception;
            }
            $success = false;
        }
        if (! $success) {
            throw ValidationException::withMessages(['code' => __('community.whatsapp.invalid_code')]);
        }
        $user->refresh();
    }

    public function revoke(User $user, ?User $actor = null): void
    {
        DB::transaction(function () use ($user, $actor): void {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['whatsapp_phone' => null, 'whatsapp_phone_hash' => null, 'whatsapp_verified_at' => null])->save();
            // Consumate e non cancellate: le righe tengono vivi i limiti d'invio, e una
            // revoca non deve diventare il modo di azzerarli. La cancellazione resta a DeleteAccount.
            WhatsappChallenge::query()->where('user_id', $user->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
            activity('community')->causedBy($actor ?? $locked)->performedOn($locked)->event('whatsapp_revoked')->log('whatsapp_revoked');
        });
        $user->refresh();
    }

    public function fingerprint(string $phone): string
    {
        // Nessun ripiego su APP_KEY: ruotarla cambierebbe ogni impronta e riaprirebbe i numeri già usati.
        $key = (string) config('community.phone_hash_key');
        if ($key === '') {
            throw new \RuntimeException('WHATSAPP_PHONE_HASH_KEY is not configured.');
        }

        return hash_hmac('sha256', $phone, $key);
    }
}
