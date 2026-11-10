<?php

declare(strict_types=1);

namespace Tests\Raneomik\NettePWA\Unit\Bridge\Latte;

require \dirname(__DIR__, 3) . '/bootstrap.php';

use Latte\Engine;
use Latte\Loaders\StringLoader;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use Raneomik\NettePWA\Bridge\Latte\PWALatteExtension;
use Raneomik\NettePWA\Manifest\Value\Manifest;
use Tester\Assert;
use Tester\TestCase;

/**
 * @testCase
 */
final class PWALatteExtensionTest extends TestCase
{
    public function testManifestAndThemeColorWithoutPush(): void
    {
        Assert::same(
            '<link rel="manifest" href="/app/manifest.webmanifest">' . "\n" . '<meta name="theme-color" content="#123">',
            $this->render([
                'name' => 'Demo',
                'themeColor' => '#123',
            ], ''),
        );
        Assert::same('<link rel="manifest" href="/app/manifest.webmanifest">', $this->render([
            'name' => 'Demo',
        ], null));
    }

    public function testPushMetasWithAKey(): void
    {
        Assert::same(
            '<link rel="manifest" href="/app/manifest.webmanifest">' . "\n"
            . '<meta name="pwa-vapid-key" content="KEY">' . "\n"
            . '<meta name="pwa-subscribe-url" content="/app/pwa/push/subscribe">' . "\n"
            . '<meta name="pwa-sw-url" content="/app/sw.js">',
            $this->render([
                'name' => 'Demo',
            ], 'KEY'),
        );
    }

    public function testSubscribeUrlFollowsTheConfiguredPath(): void
    {
        foreach (['pwa-subscribe', '/pwa-subscribe'] as $path) { // same route either way: relative to the base path
            Assert::contains(
                '<meta name="pwa-subscribe-url" content="/app/pwa-subscribe">',
                $this->render([
                    'name' => 'Demo',
                ], 'KEY', $path),
            );
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function render(array $manifest, ?string $key, string $subscribePath = 'pwa/push/subscribe'): string
    {
        $latte = new Engine();
        $latte->setLoader(new StringLoader());
        $latte->addExtension(new PWALatteExtension(
            new Manifest(...$manifest),
            new Request(new UrlScript('https://example.com/app/x', '/app/')),
            $key,
            $subscribePath,
        ));

        return $latte->renderToString('{pwaHead()}');
    }
}

(new PWALatteExtensionTest())->run();
