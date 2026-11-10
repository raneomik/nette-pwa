<?php

declare(strict_types=1);

namespace Tests\Raneomik\NettePWA\Unit\Action;

require \dirname(__DIR__, 2) . '/bootstrap.php';

use Nette\Http\Request;
use Nette\Http\Response;
use Nette\Http\UrlScript;
use Raneomik\NettePWA\Action\ManifestAction;
use Raneomik\NettePWA\Manifest\ManifestProvider;
use Raneomik\NettePWA\Manifest\Value\Manifest;
use Tester\Assert;
use Tester\TestCase;

/**
 * @testCase
 */
final class ManifestActionTest extends TestCase
{
    public function testServesTheProviderJson(): void
    {
        $provider = new ManifestProvider(new Manifest('Demo'));
        $httpResponse = new Response();
        $httpResponse->warnOnBuffer = false;

        ob_start();
        (new ManifestAction($provider))()->send(new Request(new UrlScript('https://example.com/')), $httpResponse);

        Assert::same($provider->getJson(), ob_get_clean());
    }
}

(new ManifestActionTest())->run();
