<?php

declare(strict_types=1);

namespace Tests\Raneomik\NettePWA\Fixtures;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Raneomik\NettePWA\Push\WebPushFactory;

/**
 * Real WebPush (VAPID, encryption) over a fake push service: the HTTP status is chosen per endpoint.
 */
final class FakePushService implements ClientInterface, WebPushFactory
{
    /**
     * @var list<string> endpoints called, in order
     */
    public array $calls = [];

    public int $created = 0;

    /**
     * @param array<string, int> $statuses endpoint => HTTP status (201 when missing)
     */
    public function __construct(
        private readonly array $statuses = [],
    ) {
    }

    public function create(): WebPush
    {
        ++$this->created;
        $factory = new Psr17Factory();

        return new WebPush([
            'VAPID' => [
                'subject' => 'mailto:test@example.com',
            ] + VAPID::createVapidKeys(),
        ], [], $this, $factory, $factory);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $endpoint = (string) $request->getUri();
        $this->calls[] = $endpoint;

        return new Response($this->statuses[$endpoint] ?? 201);
    }

    /**
     * a browser-like subscription: a real P-256 public key, or the payload cannot be encrypted
     */
    public static function subscription(string $endpoint): Subscription
    {
        $pair = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => \OPENSSL_KEYTYPE_EC,
        ]);
        $key = false === $pair ? false : openssl_pkey_get_details($pair);
        \assert(\is_array($key));
        $b64 = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        return new Subscription($endpoint, $b64("\x04" . $key['ec']['x'] . $key['ec']['y']), $b64(random_bytes(16)));
    }
}
