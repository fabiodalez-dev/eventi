<?php

declare(strict_types=1);

namespace App\Services\Community;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class KapsoClient
{
    public function available(): bool
    {
        return config('community.whatsapp_enabled') && filled(config('community.kapso_key'))
            && filled(config('community.phone_number_id')) && filled(config('community.template'));
    }

    public function send(string $phone, #[\SensitiveParameter] string $code): bool
    {
        if (! $this->available()) {
            return false;
        }
        try {
            $response = Http::withHeaders(['X-API-Key' => config('community.kapso_key')])
                ->acceptJson()->connectTimeout(3)->timeout(8)
                ->post('https://api.kapso.ai/meta/whatsapp/'.config('community.api_version').'/'.config('community.phone_number_id').'/messages', [
                    'messaging_product' => 'whatsapp', 'to' => ltrim($phone, '+'), 'type' => 'template',
                    'template' => ['name' => config('community.template'), 'language' => ['code' => config('community.language')],
                        'components' => [
                            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]],
                            ['type' => 'button', 'sub_type' => 'otp', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $code]]],
                        ]],
                ]);

            return $response->successful() && filled($response->json('messages.0.id'));
        } catch (ConnectionException) {
            // Never log an HTTP exception carrying the key, recipient or OTP.
            return false;
        }
    }
}
