<?php

declare(strict_types=1);

namespace Tests\Raneomik\NettePWA\Unit\Push;

require \dirname(__DIR__, 2) . '/bootstrap.php';

use Minishlink\WebPush\MessageSentReport;
use Raneomik\NettePWA\Push\Notification;
use Raneomik\NettePWA\Push\PushSender;
use Tester\Assert;
use Tester\TestCase;
use Tests\Raneomik\NettePWA\Fixtures\FakePushService;
use Tests\Raneomik\NettePWA\Fixtures\MemoryStorage;

/**
 * @testCase
 */
final class PushSenderTest extends TestCase
{
    private const string Ok = 'https://push.test/ok';

    private const string Gone = 'https://push.test/gone';

    private const string Down = 'https://push.test/down';

    private MemoryStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new MemoryStorage();
        $this->storage->save(FakePushService::subscription(self::Ok), ['room/a', 'player/1']);
        $this->storage->save(FakePushService::subscription(self::Gone), ['room/a']);
        $this->storage->save(FakePushService::subscription(self::Down), ['room/b']);
    }

    public function testSendsToEveryListenerOnce(): void
    {
        $service = new FakePushService();
        $failures = (new PushSender($service, $this->storage))->send(['room/a', 'player/1'], new Notification('Hi'));

        Assert::same([], $failures);
        Assert::same([self::Ok, self::Gone], $service->calls);
        Assert::count(3, $this->storage->rows);
    }

    public function testSingleTopic(): void
    {
        $service = new FakePushService();
        (new PushSender($service, $this->storage))->send('player/1', new Notification('Hi'));

        Assert::same([self::Ok], $service->calls);
    }

    public function testExpiredRemovedOtherFailuresReturned(): void
    {
        $service = new FakePushService([
            self::Gone => 410,
            self::Down => 500,
            self::Ok => 429, // rate limited: a failure, not an expiry
        ]);
        $failures = (new PushSender($service, $this->storage))->send(['room/a', 'room/b'], new Notification('Hi'));

        Assert::same([self::Ok, self::Down], array_map(static fn (MessageSentReport $report): string => $report->getEndpoint(), $failures));
        Assert::same([self::Ok, self::Down], array_keys($this->storage->rows)); // gone: removed, down: kept for next time
    }

    public function testFreshWebPushPerSendAndNoneWithoutListeners(): void
    {
        $service = new FakePushService();
        $sender = new PushSender($service, $this->storage);
        $sender->send('room/a', new Notification('Hi'));
        $sender->send('room/a', new Notification('Hi'));
        Assert::same(2, $service->created);
        Assert::count(4, $service->calls); // 2 each: nothing left in a queue from the first send

        $sender->send('room/nobody', new Notification('Hi'));
        $sender->send([], new Notification('Hi'));
        Assert::same(2, $service->created);
    }
}

(new PushSenderTest())->run();
