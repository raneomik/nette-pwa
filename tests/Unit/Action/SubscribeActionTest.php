<?php

declare(strict_types=1);

namespace Tests\Raneomik\NettePWA\Unit\Action;

require \dirname(__DIR__, 2) . '/bootstrap.php';

use Minishlink\WebPush\Subscription;
use Nette\Application\BadRequestException;
use Nette\Application\Response;
use Nette\Application\Responses\JsonResponse;
use Nette\Application\Responses\VoidResponse;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use Nette\Utils\Json;
use Raneomik\NettePWA\Action\SubscribeAction;
use Raneomik\NettePWA\Push\PushServices;
use Tester\Assert;
use Tester\TestCase;
use Tests\Raneomik\NettePWA\Fixtures\MemoryStorage;
use Tests\Raneomik\NettePWA\Fixtures\PrefixAuthorizer;

/**
 * @testCase
 */
final class SubscribeActionTest extends TestCase
{
    private const string Endpoint = 'https://fcm.googleapis.com/fcm/send/abc';

    private MemoryStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new MemoryStorage();
    }

    public function testStoresTheAllowedTopicsOnly(): void
    {
        $response = $this->action('POST', $this->body([
            'player/2',
            'room/a',
            'room/a',
            42,
            '',
        ]))(); // allowed one not first: a list is still stored

        \assert($response instanceof JsonResponse);
        Assert::same('{"topics":["room/a"]}', Json::encode($response->getPayload())); // what push-client.js receives
        Assert::same(['room/a'], $this->storage->rows[self::Endpoint][1]);
    }

    public function testAcceptsTheLimits(): void
    {
        $this->action('POST', $this->body([...$this->roomTopics(19), $long = 'room/' . str_repeat('a', 250)]))();

        Assert::count(20, $topics = $this->storage->rows[self::Endpoint][1]);
        Assert::contains($long, $topics); // 255 characters
    }

    public function testAcceptsTopicAsString(): void
    {
        $this->action('POST', $this->body('room/a'))();

        Assert::same(['room/a'], $topics = $this->storage->rows[self::Endpoint][1]);
    }

    /**
     * @return iterable<string, array{string, ?string, bool, int, string}>
     */
    public function provideRefusals(): iterable
    {
        $valid = $this->body(['room/a']);

        yield 'GET' => ['GET', $valid, true, 403, 'Forbidden'];
        yield 'PATCH' => ['PATCH', $valid, true, 403, 'Forbidden'];
        yield 'DELETE without X-Requested-With' => ['DELETE', '{"endpoint":"' . self::Endpoint . '"}', false, 403, 'Forbidden'];
        yield 'JSON not an object' => ['POST', '"room/a"', true, 400, 'Invalid JSON'];
        yield 'no X-Requested-With' => ['POST', $valid, false, 403, 'Forbidden'];

        yield 'missing data' => ['POST', '{}', true, 400, "Missing input at 'subscription'."];
        yield 'missing topics' => ['POST', $this->body(false), true, 403, "Missing input at 'topics'."];
        yield 'no topic allowed' => ['POST', $this->body(['player/2']), true, 403, 'No topic you may subscribe to.'];
        yield 'invalid JSON' => ['POST', '{nope', true, 400, 'Invalid JSON'];
        yield 'empty body' => ['POST', null, true, 400, 'Invalid JSON'];
        yield 'no topics' => ['POST', $this->body([]), true, 403, 'No topic you may subscribe to.'];
        yield 'too many topics' => ['POST', $this->body($this->roomTopics(21)), true, 403, '21 is too much topics. 20 only are allowed.'];
        yield 'topic too long' => ['POST', $this->body(['room/' . str_repeat('a', 251)]), true, 403, 'No topic you may subscribe to.'];
        yield 'endpoint not a push service' => ['POST', $this->body(['room/a'], 'https://127.0.0.1/x'), true, 400, 'Invalid subscription endpoint'];
        yield 'no endpoint' => ['POST', str_replace('"endpoint"', '"nope"', $valid), true, 400, 'Endpoint must be a string.'];
        yield 'invalid p256dh' => ['POST', $this->body(['room/a'], p256dh: 'short'), true, 400, 'p256dh must be a string & in base64url format.'];
        yield 'invalid auth' => ['POST', $this->body(['room/a'], auth: 'x'), true, 400, 'auth must be a string & in base64url format.'];
        yield 'auth not base64url' => ['POST', $this->body(['room/a'], auth: 'tBHItJI5svbpez7KI4CC+g'), true, 400, 'auth must be a string & in base64url format.'];
    }

    /**
     * @dataProvider provideRefusals
     */
    public function testRefusals(string $method, ?string $body, bool $ajax, int $code, string $message): void
    {
        Assert::exception(fn (): Response => $this->action($method, $body, $ajax)(), BadRequestException::class, $message, $code);
        Assert::same([], $this->storage->rows);
    }

    public function testUnsubscribeRemovesTheEndpoint(): void
    {
        $this->action('POST', $this->body(['room/a']))();
        $this->storage->save(new Subscription('https://fcm.googleapis.com/fcm/send/other', 'k', 'a'), ['room/a']);

        $response = $this->action('DELETE', Json::encode([
            'endpoint' => self::Endpoint,
        ]))();

        Assert::type(VoidResponse::class, $response);
        Assert::same(['https://fcm.googleapis.com/fcm/send/other'], array_keys($this->storage->rows));
    }

    public function testUnsubscribeIsIdempotent(): void
    {
        Assert::type(VoidResponse::class, $this->action('DELETE', Json::encode([
            'endpoint' => self::Endpoint,
        ]))());
        Assert::same([], $this->storage->rows);
    }

    public function testRotateMovesTheTopicsToTheNewEndpoint(): void
    {
        $this->action('POST', $this->body(['room/a']))();
        $new = 'https://fcm.googleapis.com/fcm/send/new';

        $response = $this->action('PUT', $this->rotation(self::Endpoint, $new))();

        Assert::type(VoidResponse::class, $response);
        Assert::same([$new], array_keys($this->storage->rows));
        Assert::same(['room/a'], $this->storage->rows[$new][1], 'the topics authorized at subscription are kept');
        Assert::same('tBHItJI5svbpez7KI4CCXg', $this->storage->rows[$new][0]->getAuthToken());
    }

    /**
     * @return iterable<string, array{string, ?string, int, string}>
     */
    public function provideInvalidChanges(): iterable
    {
        $new = 'https://fcm.googleapis.com/fcm/send/new';

        yield 'unsubscribe without endpoint' => ['DELETE', '{}', 400, 'Invalid subscription endpoint'];
        yield 'unsubscribe from a non push service' => ['DELETE', '{"endpoint":"https://127.0.0.1/x"}', 400, 'Invalid subscription endpoint'];
        yield 'unsubscribe with invalid JSON' => ['DELETE', '{nope', 400, 'Invalid JSON'];
        yield 'rotate an unknown endpoint' => ['PUT', $this->rotation('https://fcm.googleapis.com/fcm/send/unknown', $new), 404, 'Unknown subscription'];
        yield 'rotate from a non push service' => ['PUT', $this->rotation('https://127.0.0.1/x', $new), 400, 'Invalid subscription endpoint'];
        yield 'rotate to a non push service' => ['PUT', $this->rotation(self::Endpoint, 'https://127.0.0.1/x'), 400, 'Invalid subscription endpoint'];
        yield 'rotate without new subscription' => ['PUT', Json::encode([
            'oldEndpoint' => self::Endpoint,
        ]), 400, "Missing input at 'subscription'."];
    }

    /**
     * @dataProvider provideInvalidChanges
     */
    public function testInvalidChangesLeaveTheStorageUntouched(string $method, string $body, int $code, string $message): void
    {
        $this->action('POST', $this->body(['room/a']))();
        $before = $this->storage->rows;

        Assert::exception(fn (): Response => $this->action($method, $body)(), BadRequestException::class, $message, $code);
        Assert::same($before, $this->storage->rows);
    }

    private function rotation(string $oldEndpoint, string $newEndpoint): string
    {
        $new = Json::decode($this->body(false, $newEndpoint), forceArrays: true);

        return Json::encode([
            'oldEndpoint' => $oldEndpoint,
        ] + $new);
    }

    private function action(string $method, ?string $body, bool $ajax = true): SubscribeAction
    {
        return new SubscribeAction(
            new Request(
                new UrlScript('https://example.com/pwa/push/subscribe'),
                headers: $ajax ? [
                    'X-Requested-With' => 'XMLHttpRequest',
                ] : [],
                method: $method,
                rawBodyCallback: static fn (): string => $body ?? '', // what an empty POST gives
            ),
            new PrefixAuthorizer('room/'),
            new PushServices(),
            $this->storage,
        );
    }

    private function body(
        mixed $topics,
        string $endpoint = self::Endpoint,
        string $p256dh = 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM',
        string $auth = 'tBHItJI5svbpez7KI4CCXg',
    ): string {
        $topics = false !== $topics
            ? [
                'topics' => $topics,
            ]
            : [];

        return Json::encode([
            'subscription' => [
                'endpoint' => $endpoint,
                'keys' => [
                    'p256dh' => $p256dh,
                    'auth' => $auth,
                ],
            ],
            ...$topics,
        ]);
    }

    /**
     * @return list<string>
     */
    private function roomTopics(int $count): array
    {
        return array_map(static fn (int $i): string => "room/{$i}", range(1, $count));
    }
}

(new SubscribeActionTest())->run();
