<?php

declare(strict_types=1);

namespace Tests\Raneomik\NettePWA\Unit\Push;

require \dirname(__DIR__, 2) . '/bootstrap.php';

use Nette\Utils\Json;
use Raneomik\NettePWA\Push\Notification;
use Tester\Assert;
use Tester\TestCase;

/**
 * @testCase
 */
final class NotificationTest extends TestCase
{
    public function testNullMembersLeftOut(): void
    {
        // read by assets/sw.js, which has its own defaults
        Assert::same('{"title":"Hi","url":"/room"}', Json::encode(new Notification('Hi', url: '/room')));
        Assert::same('{"title":"Hi","body":"b","url":"/","tag":"t"}', Json::encode(new Notification('Hi', 'b', '/', 't')));
    }
}

(new NotificationTest())->run();
