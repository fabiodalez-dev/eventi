<?php

namespace App\Services\Social;

use App\Enums\SocialPublicationStatus as Status;
use App\Models\SocialBatch;
use App\Models\SocialConnection;
use App\Models\SocialPublication;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class TelegramPublisher
{
    public function verify(SocialConnection $connection): void
    {
        $connection->update(['telegram_verified_at' => null]);
        $bot = $this->request($connection, 'getMe');
        $chat = $this->request($connection, 'getChat', ['chat_id' => $connection->telegram_chat_id]);
        $member = $this->request($connection, 'getChatMember', ['chat_id' => $connection->telegram_chat_id, 'user_id' => $bot['id'] ?? 0]);
        if (($chat['type'] ?? '') !== 'channel' || ($member['status'] ?? '') !== 'administrator' || ! ($member['can_post_messages'] ?? false)) {
            throw new RuntimeException('Il bot deve essere amministratore di un canale Telegram con il permesso di pubblicare messaggi.');
        }
        $connection->update(['telegram_verified_at' => now()]);
    }

    public function publish(SocialPublication $publication, SocialConnection $connection, SocialBatch $batch): void
    {
        if (! $connection->telegram_enabled || ! $connection->telegram_verified_at || ($publication->remote_ids['telegram_chat_id'] ?? null) !== $connection->telegram_chat_id) {
            throw new RuntimeException('Collegamento Telegram assente, modificato o non verificato.');
        }
        $items = array_slice($batch->items, $publication->part * 10, 10);
        $caption = $batch->options['captions'][$publication->part];
        if (mb_strlen($caption) > 1024) {
            throw new RuntimeException('Telegram ammette 1024 caratteri nella didascalia delle immagini. Accorcia il modello e prepara un nuovo contenuto.');
        }
        $media = [];
        foreach ($items as $index => $item) {
            $media[] = ['type' => 'photo', 'media' => SocialStudio::imageUrl($batch, $publication->part * 10 + $index), 'caption' => $index === 0 ? $caption : ''];
        }
        // As for Meta, never retry an ambiguous publish automatically.
        $publication->update(['status' => Status::Uncertain, 'error' => 'Esito incerto: controlla il canale Telegram prima di riprovare.']);
        $result = count($media) === 1
            ? $this->request($connection, 'sendPhoto', ['chat_id' => $connection->telegram_chat_id, 'photo' => $media[0]['media'], 'caption' => $caption])
            : $this->request($connection, 'sendMediaGroup', ['chat_id' => $connection->telegram_chat_id, 'media' => $media]);
        $ids = count($media) === 1 ? [$result['message_id'] ?? null] : array_column($result, 'message_id');
        if ($ids === [] || in_array(null, $ids, true)) {
            return;
        }
        $publication->update(['status' => Status::Published, 'external_id' => (string) $ids[0],
            'remote_ids' => [...($publication->remote_ids ?? []), 'messages' => $ids], 'error' => null]);
    }

    /** @param array<string, mixed> $data
     * @return array<mixed>
     */
    private function request(SocialConnection $connection, string $method, array $data = []): array
    {
        if (! preg_match('/^\d+:[A-Za-z0-9_-]+$/', (string) $connection->telegram_bot_token)) {
            throw new RuntimeException('Token Telegram mancante o non valido.');
        }
        try {
            $response = Http::connectTimeout(10)->timeout(30)->post('https://api.telegram.org/bot'.$connection->telegram_bot_token.'/'.$method, $data);
        } catch (\Throwable) {
            // Telegram embeds the credential in the URL. Never propagate its exception text.
            throw new RuntimeException('Telegram non raggiungibile; verifica l’esito nel canale prima di riprovare.');
        }
        if (! $response->successful() || ! $response->json('ok')) {
            throw new RuntimeException('Telegram ha rifiutato la richiesta. Verifica token, canale e permessi del bot.');
        }

        return $response->json('result') ?: [];
    }
}
