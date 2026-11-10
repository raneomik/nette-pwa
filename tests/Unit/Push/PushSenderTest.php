<?php

declare(strict_types=1);

namespace Tests\Raneomik\NettePWA\Unit\Push;

require \dirname(__DIR__, 2) . '/bootstrap.php';

use Minishlink\WebPush\MessageSentReport;
use Nyholm\Psr7\Request;
use Raneomik\NettePWA\Push\Debug\SendLogger;
use Raneomik\NettePWA\Push\Debug\SentPush;
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

    public function testEverySendIsLoggedInDebugMode(): void
    {
        $log = new SendLogger();
        $sender = new PushSender(new FakePushService([
            self::Gone => 410,
            self::Down => 500,
        ]), $this->storage, $log);
        $sender->send(['room/a', 'room/b'], new Notification('Hi', url: 'https://app.test/game/1'));
        $sender->send('room/nobody', new Notification('Hello'));

        [$first, $nobody] = $log->drain();
        Assert::type(SentPush::class, $first);
        Assert::same(['room/a', 'room/b'], $first->topics);
        Assert::same('https://app.test/game/1', $first->notification->url);
        Assert::same(3, $first->recipients);
        Assert::same(1, $first->delivered);
        Assert::same(['push.test'], $first->expired, 'hosts only: an endpoint is a capability URL');
        Assert::same([[
            'service' => 'push.test',
            'status' => 500,
            'reason' => $first->failures[0]['reason'],
        ]], $first->failures);
        Assert::true(0 < $first->milliseconds && 60_000 > $first->milliseconds, 'elapsed time, in ms');

        Assert::same(0, $nobody->recipients, 'a send without listeners is logged too');
        Assert::same([], $log->drain(), 'drained by the panel');
    }

    public function testAFailureWithoutResponseIsLogged(): void
    {
        $log = new SendLogger();
        $log->record(['room/a'], new Notification('Hi'), 1, [
            new MessageSentReport(new Request('POST', 'https://push.test/x'), null, false, 'Connection timed out'),
        ], 1.0);

        Assert::same([[
            'service' => 'push.test',
            'status' => null, // network error: no HTTP status
            'reason' => 'Connection timed out',
        ]], $log->drain()[0]->failures);
    }

    public function testTheLogIsCapped(): void
    {
        $log = new SendLogger();
        $sender = new PushSender(new FakePushService(), $this->storage, $log);
        for ($i = 0; 60 > $i; ++$i) {
            $sender->send('room/nobody', new Notification((string) $i));
        }

        $sent = $log->drain();
        Assert::count(50, $sent, 'worker mode: a log never drained (no bar) must not grow forever');
        Assert::same('59', $sent[49]->notification->title);
    }
}

(new PushSenderTest())->run();
