<?php

declare(strict_types=1);

namespace App\Services\Ticketing;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class GoogleWallet
{
    public function configured(): bool
    {
        foreach (['issuer_id', 'class_id', 'service_account_email', 'private_key'] as $key) {
            if (blank(config('wallet.google.'.$key))) {
                return false;
            }
        }

        return true;
    }

    public function objectId(int $ticketId): string
    {
        return config()->string('wallet.google.issuer_id').'.incitta-'.$ticketId;
    }

    /** Create before issuing a save link, so cancellation never races a later first save.
     * @param  array<string, mixed>  $object
     */
    public function create(array $object): void
    {
        $response = Http::withToken($this->accessToken())->timeout(15)
            ->post('https://walletobjects.googleapis.com/walletobjects/v1/eventTicketObject', $object);
        if ($response->status() !== 409) {
            $response->throw();
        }
    }

    public function deactivate(string $objectId): void
    {
        $response = Http::withToken($this->accessToken())->timeout(15)
            ->patch('https://walletobjects.googleapis.com/walletobjects/v1/eventTicketObject/'.rawurlencode($objectId), ['state' => 'INACTIVE']);
        if ($response->status() !== 404) {
            $response->throw();
        }
    }

    private function accessToken(): string
    {
        $email = config()->string('wallet.google.service_account_email');
        $key = config()->string('wallet.google.private_key');

        return Cache::remember('wallet-oauth:'.hash('sha256', $email.$key), 3000, function () use ($email, $key): string {
            $assertion = JWT::encode([
                'iss' => $email, 'scope' => 'https://www.googleapis.com/auth/wallet_object.issuer',
                'aud' => 'https://oauth2.googleapis.com/token', 'iat' => time(), 'exp' => time() + 3600,
            ], str_replace('\\n', "\n", $key), 'RS256');
            $token = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion,
            ])->throw()->json('access_token');
            if (! is_string($token) || $token === '') {
                throw new \RuntimeException('Google Wallet returned no access token.');
            }

            return $token;
        });
    }
}
