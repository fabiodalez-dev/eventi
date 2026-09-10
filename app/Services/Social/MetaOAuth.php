<?php

namespace App\Services\Social;

use App\Models\SocialConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class MetaOAuth
{
    public const SCOPES = 'pages_show_list,pages_read_engagement,pages_manage_posts,instagram_basic,instagram_content_publish';

    public function loginUrl(SocialConnection $connection, string $state): string
    {
        $this->validateApp($connection);

        return 'https://www.facebook.com/'.$connection->graph_version.'/dialog/oauth?'.http_build_query([
            'client_id' => $connection->app_id, 'redirect_uri' => route('social.meta.callback'),
            'state' => $state, 'scope' => self::SCOPES, 'response_type' => 'code',
        ]);
    }

    /** @return list<array{id:string,name:string,access_token:string,instagram_id:?string}> */
    public function pages(SocialConnection $connection, string $code): array
    {
        $this->validateApp($connection);
        $base = 'https://graph.facebook.com/'.$connection->graph_version;
        $short = $this->response(Http::asForm()->connectTimeout(10)->timeout(30)->post($base.'/oauth/access_token', [
            'client_id' => $connection->app_id, 'client_secret' => $connection->app_secret,
            'redirect_uri' => route('social.meta.callback'), 'code' => $code,
        ]));
        if (empty($short['access_token'])) {
            throw new RuntimeException('Meta non ha restituito un token.');
        }
        $long = $this->response(Http::asForm()->connectTimeout(10)->timeout(30)->post($base.'/oauth/access_token', [
            'grant_type' => 'fb_exchange_token', 'client_id' => $connection->app_id,
            'client_secret' => $connection->app_secret, 'fb_exchange_token' => $short['access_token'],
        ]));
        if (empty($long['access_token'])) {
            throw new RuntimeException('Meta non ha restituito un token esteso.');
        }
        $pages = [];
        $cursor = null;
        for ($page = 0; $page < 20; $page++) {
            $result = $this->response(Http::withToken($long['access_token'])->connectTimeout(10)->timeout(30)->get($base.'/me/accounts', array_filter([
                'fields' => 'id,name,access_token,instagram_business_account', 'limit' => 100, 'after' => $cursor,
            ])));
            foreach ($result['data'] ?? [] as $item) {
                if (empty($item['id']) || empty($item['access_token'])) {
                    continue;
                }
                $pages[] = ['id' => (string) $item['id'], 'name' => (string) ($item['name'] ?? $item['id']),
                    'access_token' => (string) $item['access_token'], 'instagram_id' => isset($item['instagram_business_account']['id']) ? (string) $item['instagram_business_account']['id'] : null];
            }
            $cursor = isset($result['paging']['next']) ? ($result['paging']['cursors']['after'] ?? null) : null;
            if (! $cursor) {
                break;
            }
        }

        return $pages;
    }

    public function fingerprint(SocialConnection $connection): string
    {
        return hash('sha256', implode('|', [$connection->app_id, $connection->app_secret, $connection->graph_version, $connection->city_id]));
    }

    private function validateApp(SocialConnection $connection): void
    {
        if (! preg_match('/^\d+$/', (string) $connection->app_id) || ! $connection->app_secret || ! preg_match('/^v\d+\.\d+$/', $connection->graph_version)) {
            throw new RuntimeException('Salva App ID, App Secret e una versione Graph valida prima di collegare Meta.');
        }
    }

    /** @return array<string, mixed> */
    private function response(Response $response): array
    {
        if (! $response->successful()) {
            throw new RuntimeException('Autenticazione Meta non riuscita. Verifica credenziali, URL OAuth e permessi dell’app.');
        }

        return $response->json() ?: [];
    }
}
