<?php

declare(strict_types=1);

namespace Tests\Raneomik\NettePWA\Unit\Bridge\Tracy;

require \dirname(__DIR__, 3) . '/bootstrap.php';

use Nette\Http\Request;
use Nette\Http\UrlScript;
use Nette\Utils\FileSystem;
use Raneomik\NettePWA\Bridge\Tracy\PWAPanel;
use Raneomik\NettePWA\Manifest\ManifestInspector;
use Raneomik\NettePWA\Manifest\Value\Display;
use Raneomik\NettePWA\Manifest\Value\Icon;
use Raneomik\NettePWA\Manifest\Value\Manifest;
use Raneomik\NettePWA\Push\Debug\SendLogger;
use Raneomik\NettePWA\Push\Notification;
use Raneomik\NettePWA\Push\PushSender;
use Tester\Assert;
use Tester\TestCase;
use Tests\Raneomik\NettePWA\Fixtures\FakePushService;
use Tests\Raneomik\NettePWA\Fixtures\MemoryStorage;

/**
 * @testCase
 */
final class PWAPanelTest extends TestCase
{
    private const string PackageWorker = __DIR__ . '/../../../../assets/sw.js';

    private const array Push = [
        'publicKey' => 'KEY',
        'subject' => 'mailto:dev@example.com',
        'pushServices' => ['fcm.googleapis.com'],
        'subscribePath' => 'pwa/push/subscribe',
    ];

    public function testAHealthyAppShowsNoError(): void
    {
        $panel = $this->panel(push: self::Push);

        Assert::match('%A%<svg %a% style="width:22.1px;height:13px;vertical-align:middle" role="img" aria-label="PWA"><defs><mask id="pwa-logo-%h%"%A%mask="url(#pwa-logo-%h%)"%A%#5A0FC8"%A%</svg>%A%', $tab = $panel->getTab());
        Assert::notContains('{ID}', $tab);
        Assert::notContains('tracy-label', $tab, 'nothing to count: no empty label padding the logo on the right');
        Assert::match('%A%<h1><svg %a% viewBox="0 0 1952 735" style="height:1em;width:2.66em;vertical-align:middle" role="img" aria-label="PWA">%A%#5A0FC8%A%</svg></h1>%A%', $panel->getPanel());
        Assert::notContains('<!--', $panel->getTab(), 'the credit stays in the source file');
        Assert::match('%A%<h1><svg %a% style="height:1em;%A%</svg></h1>%A%', $panel->getPanel());
        Assert::notContains('background:#c00', $panel->getTab());
        Assert::match('%A%Package file (assets/sw.js)%A%Served from a secure context.%A%Nothing sent since the last panel.%A%', $panel->getPanel());
    }

    public function testErrorsAreCountedInTheTab(): void
    {
        // plain HTTP on a real host, and a manifest without any icon, not installable
        $panel = $this->panel('http://example.com/', manifest: new Manifest('Demo', display: Display::Browser));

        Assert::match('%A%<span style="color:#fff;background:#c00%a%">3</span>%A%', $panel->getTab());
        Assert::match('%A%Not a secure context (http://example.com): no service worker, no install, no push.%A%', $panel->getPanel());
    }

    public function testIssuesAreListedErrorsFirst(): void
    {
        $html = $this->panel(icons: [new Icon('https://cdn.example.net/icon.png', '96x96')])->getPanel();

        // checked: maskable (tip) before the icon size (error); shown: the error first
        Assert::match('%A%class="pwa-issue pwa-error"%A%No icon of at least 144x144%A%class="pwa-issue pwa-tip"%A%maskable%A%', $html);
    }

    public function testTheManifestIsInspectedAtItsUrl(): void
    {
        // app in a subdirectory: icons/x.png = /app/icons/x.png = {wwwDir}/icons/x.png
        $html = $this->panel('https://example.com/app/', manifest: new Manifest('Demo', icons: [new Icon('icons/x.png', '512x512')]), scriptPath: '/app/', wwwDir: '/www')->getPanel();

        Assert::contains('icons/x.png: not found (/www/icons/x.png).', $html);
    }

    public function testLocalhostIsASecureContext(): void
    {
        Assert::contains('Served from a secure context.', $this->panel('http://localhost:8000/')->getPanel());
    }

    public function testACustomServiceWorkerMustHandleThePushEvents(): void
    {
        $file = __DIR__ . '/../../../output/sw-' . getmypid() . '.js';
        FileSystem::write($file, "self.addEventListener('fetch', () => {});");

        try {
            $panel = $this->panel(push: self::Push, serviceWorker: $file);
            $html = $panel->getPanel();
            Assert::match('%A%background:#c00%a%">1</span>%A%', $panel->getTab(), 'only the push listener is an error');
            Assert::notContains('listener', $this->panel(serviceWorker: $file)->getPanel(), 'without push, no listener to check');

            FileSystem::write($file, "self.addEventListener('push', () => {});");
            $html2 = $this->panel(push: self::Push, serviceWorker: $file)->getPanel();
            Assert::notContains('No &quot;push&quot; listener', $html2);
            Assert::contains('No &quot;notificationclick&quot; listener', $html2);
        } finally {
            FileSystem::delete($file);
        }

        Assert::match('%A%No &quot;push&quot; listener: push notifications are never shown. Start from%A%', $html);
        Assert::match('%A%No &quot;notificationclick&quot; listener: a click on a notification opens nothing.%A%No &quot;pushsubscriptionchange&quot; listener: a rotated subscription is lost.%A%', $html);
        Assert::contains('App file: ' . $file, $html);

        $html = $this->panel('http://example.com/', push: self::Push, serviceWorker: '/nowhere/sw.js')->getPanel();
        Assert::match('%A%Not a secure context%A%/nowhere/sw.js: not found, /sw.js fails.%A%', $html);
        Assert::notContains('listener', $html, 'a missing file has no listener to check');
    }

    public function testPushConfigurationWarnings(): void
    {
        Assert::contains('Not configured (pwa: push).', $this->panel()->getPanel());
        Assert::contains(
            'Empty VAPID keys: push is off',
            $this->panel(push: [
                'publicKey' => '',
            ] + self::Push)->getPanel(),
        );
        Assert::contains(
            'Apple refuses localhost',
            $this->panel(push: [
                'subject' => 'https://localhost',
            ] + self::Push)->getPanel(),
        );
        Assert::notContains(
            'Apple refuses localhost',
            $this->panel(push: [
                'subject' => 'https://example.com',
            ] + self::Push)->getPanel(),
        );
    }

    public function testThePushesOfTheRequest(): void
    {
        $log = new SendLogger();
        $storage = new MemoryStorage();
        $storage->save(FakePushService::subscription('https://push.test/ok'), ['room/a']);
        $storage->save(FakePushService::subscription('https://push.test/down'), ['room/a']);

        $sender = new PushSender(new FakePushService([
            'https://push.test/down' => 500,
        ]), $storage, $log);
        $sender->send('room/a', new Notification('Bob joined', 'Your turn', 'https://example.com/game/1'));
        $sender->send('room/<b>', new Notification('Nobody'));
        $sender->send('room/<b>', new Notification('Nobody'));

        $panel = $this->panel(push: self::Push, log: $log);
        $tab = $panel->getTab();
        $html = $panel->getPanel();

        Assert::match('%A%</svg><span class="tracy-label"><span style="font-weight:bold">3</span>%A%>1</span>%A%', $tab, 'three sent, one with failures');
        Assert::match('%A%class="pwa-push pwa-failed"%A%Bob joined%A%Your turn%A%room/a, opens https://example.com/game/1%A%2 recipients (en), 1 delivered%A%push.test 500:%A%', $html);
        Assert::match('%A%Nobody%A%room/&lt;b&gt;%A%title="no subscription for these topics">0 recipients<%A%', $html);
        Assert::notContains('https://push.test/down', $html, 'endpoints are never shown');
        Assert::same([], $log->drain(), 'drained: the next request starts empty (worker mode)');
    }

    /**
     * @param ?list<Icon> $icons
     * @param ?array{publicKey: string, subject: string, pushServices: list<string>, subscribePath: string} $push
     */
    private function panel(
        string $url = 'https://example.com/',
        ?array $icons = null,
        ?array $push = null,
        string $serviceWorker = self::PackageWorker,
        ?SendLogger $log = null,
        ?Manifest $manifest = null,
        string $scriptPath = '/',
        ?string $wwwDir = null,
    ): PWAPanel {
        $manifest ??= new Manifest('Demo', icons: $icons ?? [new Icon('https://cdn.example.net/icon.svg', 'any')]);

        return new PWAPanel(
            new ManifestInspector($manifest, wwwDir: $wwwDir),
            new Request(new UrlScript($url, $scriptPath)),
            $serviceWorker,
            self::PackageWorker !== $serviceWorker,
            $push,
            $log,
        );
    }
}

(new PWAPanelTest())->run();
