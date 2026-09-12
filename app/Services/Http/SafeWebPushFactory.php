<?php

declare(strict_types=1);

namespace App\Services\Http;

use App\Exceptions\ImportException;
use App\Services\Import\ImportUrlGuard;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\WebPushServiceProvider;
use Psr\Http\Message\RequestInterface;

final class SafeWebPushFactory extends WebPushServiceProvider
{
    public function make(): WebPush
    {
        $stack = HandlerStack::create(new CurlHandler);
        $stack->push(static fn (callable $next): callable => static function (RequestInterface $request, array $options) use ($next) {
            $url = (string) $request->getUri();
            if ($request->getUri()->getScheme() !== 'https' || $request->getUri()->getUserInfo() !== '') {
                throw new UnsafePushDestination('Destinazione push non valida');
            }
            try {
                $address = app(ImportUrlGuard::class)->resolvedAddress($url);
            } catch (ImportException) {
                throw new UnsafePushDestination('Destinazione push non pubblica o irraggiungibile');
            }
            $port = $request->getUri()->getPort() ?? 443;
            if ($port !== 443) {
                throw new UnsafePushDestination('Porta push non valida');
            }
            $options['proxy'] = '';
            $options['allow_redirects'] = false;
            $options['sink'] = new BoundedStream(65536);
            $options['curl'] = $address === null ? [] : [CURLOPT_RESOLVE => [$request->getUri()->getHost().':'.$port.':'.(str_contains($address, ':') ? '['.$address.']' : $address)]];

            return $next($request, $options);
        });
        $client = new Client(['handler' => $stack, 'timeout' => 30, 'connect_timeout' => 10, 'allow_redirects' => false, 'http_errors' => false]);

        return (new WebPush($this->webPushAuth(), [], $client, new HttpFactory, new HttpFactory))
            ->setReuseVAPIDHeaders(true)->setAutomaticPadding($this->webPushConfig()['automatic_padding']);
    }
}
