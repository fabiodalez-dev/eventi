<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Enums\KapsoOutcome;
use App\Enums\WhatsappDelivery;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class KapsoClient
{
    public function available(): bool
    {
        // Senza chiave dell'impronta non si può garantire «un numero, un account»: meglio spento.
        return config('community.whatsapp_enabled') && filled(config('community.kapso_key'))
            && filled(config('community.phone_number_id')) && filled(config('community.template'))
            && filled(config('community.phone_hash_key'));
    }

    public function autofillAvailable(): bool
    {
        return $this->available() && filled(config('community.android_template'));
    }

    public function send(string $phone, #[\SensitiveParameter] string $code, WhatsappDelivery $delivery = WhatsappDelivery::CopyCode): KapsoOutcome
    {
        if (! $this->available()) {
            return KapsoOutcome::Rejected;
        }
        try {
            $response = Http::withHeaders(['X-API-Key' => config('community.kapso_key')])
                ->acceptJson()->connectTimeout(3)->timeout(8)
                ->post('https://api.kapso.ai/meta/whatsapp/'.config('community.api_version').'/'.config('community.phone_number_id').'/messages', [
                    'messaging_product' => 'whatsapp', 'to' => ltrim($phone, '+'), 'type' => 'template',
                    'template' => ['name' => $delivery === WhatsappDelivery::OneTap && $this->autofillAvailable() ? config('community.android_template') : config('community.template'), 'language' => ['code' => config('community.language')],
                        'components' => [
                            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]],
                            ['type' => 'button', 'sub_type' => 'otp', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $code]]],
                        ]],
                ]);

            return $response->successful() && filled($response->json('messages.0.id')) ? KapsoOutcome::Sent : KapsoOutcome::Rejected;
        } catch (ConnectionException $exception) {
            // Mai loggare l'eccezione: porta con sé chiave, destinatario e codice.
            return $this->neverLeft($exception) ? KapsoOutcome::Rejected : KapsoOutcome::Uncertain;
        }
    }

    /**
     * Proxy o host non risolti (5, 6), connessione rifiutata (7), handshake TLS
     * fallito (35) o certificato non valido (60): la richiesta non è mai partita,
     * perché il corpo viaggia solo dopo una connessione cifrata riuscita. Ogni
     * altro errore, timeout compreso, può essere arrivato dopo che Kapso aveva
     * già accettato il messaggio.
     */
    private function neverLeft(ConnectionException $exception): bool
    {
        $previous = $exception->getPrevious();
        $context = $previous instanceof ConnectException || $previous instanceof RequestException ? $previous->getHandlerContext() : [];

        return in_array($context['errno'] ?? null, [5, 6, 7, 35, 60], true);
    }
}
