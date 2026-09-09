<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Enums\GoogleCalendarError;
use App\Models\GoogleCalendarConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Laravel\Telescope\Telescope;
use RuntimeException;

final class GoogleCalendarClient
{
    public const SCOPE = 'https://www.googleapis.com/auth/calendar.app.created';

    public function configured(): bool
    {
        return filled(config('google-calendar.client_id')) && filled(config('google-calendar.client_secret'));
    }

    public function redirectUri(): string
    {
        return config('google-calendar.redirect_uri') ?: route('google-calendar.callback');
    }

    public function authorizationUrl(string $state, string $verifier): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('google-calendar.client_id'), 'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code', 'scope' => 'openid '.self::SCOPE, 'access_type' => 'offline',
            'prompt' => 'consent select_account', 'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
    }

    /** @return array<string, mixed> */
    public function exchange(string $code, string $verifier): array
    {
        // Outbound OAuth request/response bodies contain credentials.
        if (class_exists(Telescope::class)) {
            return Telescope::withoutRecording(fn () => $this->exchangeTokens($code, $verifier));
        }

        return $this->exchangeTokens($code, $verifier);
    }

    /** @return array<string, mixed> */
    private function exchangeTokens(string $code, string $verifier): array
    {
        $response = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('google-calendar.client_id'), 'client_secret' => config('google-calendar.client_secret'),
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $this->redirectUri(), 'code_verifier' => $verifier,
        ]);
        if (! $response->successful() || ! filled($response->json('refresh_token')) || ! in_array(self::SCOPE, explode(' ', (string) $response->json('scope')), true)) {
            throw new RuntimeException('google_authorization_failed');
        }
        $identity = Http::withToken($response->json('access_token'))->timeout(20)->get('https://openidconnect.googleapis.com/v1/userinfo');
        if (! $identity->successful() || ! filled($identity->json('sub'))) {
            throw new RuntimeException('google_authorization_failed');
        }

        return [...$response->json(), 'subject' => $identity->json('sub')];
    }

    /** @param array<string, mixed> $data */
    public function request(GoogleCalendarConnection $connection, string $method, string $path, array $data = []): Response
    {
        if (class_exists(Telescope::class)) {
            return Telescope::withoutRecording(fn () => $this->calendarRequest($connection, $method, $path, $data));
        }

        return $this->calendarRequest($connection, $method, $path, $data);
    }

    /** @param array<string, mixed> $data */
    private function calendarRequest(GoogleCalendarConnection $connection, string $method, string $path, array $data): Response
    {
        if ($connection->expires_at === null || $connection->expires_at->isBefore(now()->addMinute())) {
            $response = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('google-calendar.client_id'), 'client_secret' => config('google-calendar.client_secret'),
                'grant_type' => 'refresh_token', 'refresh_token' => $connection->refresh_token,
            ]);
            if (! $response->successful()) {
                if ($response->json('error') === 'invalid_grant') {
                    $connection->update(['enabled' => false, 'error_code' => GoogleCalendarError::Authorization, 'access_token' => null, 'refresh_token' => null]);
                }
                throw new RuntimeException('google_refresh_failed');
            }
            $connection->update(['access_token' => $response->json('access_token'), 'expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600))]);
        }

        $response = Http::withToken($connection->access_token)->timeout(20)->send($method, 'https://www.googleapis.com/calendar/v3/'.$path,
            [$method === 'GET' ? 'query' : 'json' => $data]);
        if ($response->status() === 401) {
            $connection->update(['expires_at' => now()->subMinute()]);
        }

        return $response;
    }

    public function revoke(string $token): void
    {
        $send = fn () => Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/revoke', ['token' => $token]);
        $response = class_exists(Telescope::class) ? Telescope::withoutRecording($send) : $send();
        if (! $response->successful() && $response->status() !== 400) {
            throw new RuntimeException('google_revoke_failed');
        }
    }
}
