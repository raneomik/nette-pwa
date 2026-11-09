<?php

declare(strict_types=1);

namespace Tests\Raneomik\NettePWA\Unit\Push;

require \dirname(__DIR__, 2) . '/bootstrap.php';

use Raneomik\NettePWA\Push\PushServices;
use Tester\Assert;
use Tester\TestCase;

/**
 * @testCase
 */
final class PushServicesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public function provideEndpoints(): iterable
    {
        yield 'FCM' => ['https://fcm.googleapis.com/fcm/send/abc', true];
        yield 'Apple, subdomain' => ['https://web.push.apple.com/abc', true];
        yield 'Mozilla, subdomain' => ['https://updates.push.services.mozilla.com/wpush/v2/abc', true];
        yield 'Windows, subdomain' => ['https://wns2-par02p.notify.windows.com/w/?token=abc', true];
        yield 'uppercase host' => ['https://FCM.googleapis.com/x', true];
        yield 'http' => ['http://fcm.googleapis.com/x', false];
        yield 'allowed host as a prefix' => ['https://fcm.googleapis.com.attacker.io/x', false];
        yield 'allowed host as a suffix without dot' => ['https://notfcm.googleapis.com.evil/x', false];
        yield 'look-alike' => ['https://evilfcm-googleapis.com/x', false];
        yield 'ends with an allowed host, not a subdomain' => ['https://evilfcm.googleapis.com/x', false];
        yield 'metadata IP' => ['https://169.254.169.254/latest', false];
        yield 'localhost' => ['https://localhost/x', false];
        yield 'no host' => ['https:///x', false];
        yield 'not a URL' => ['not a url', false];
    }

    /**
     * @dataProvider provideEndpoints
     */
    public function testDefaultsAcceptRealPushServicesOnly(string $endpoint, bool $accepted): void
    {
        Assert::same($accepted, (new PushServices())->accepts($endpoint));
    }

    public function testCustomHosts(): void
    {
        $services = new PushServices(['push.example.com']);
        Assert::true($services->accepts('https://eu.push.example.com/x'));
        Assert::false($services->accepts('https://fcm.googleapis.com/x'));
    }
}

(new PushServicesTest())->run();
