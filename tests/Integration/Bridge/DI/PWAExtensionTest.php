<?php

declare(strict_types=1);

namespace Tests\Raneomik\NettePWA\Integration\Bridge\DI;

require \dirname(__DIR__, 3) . '/bootstrap.php';

use Base64Url\Base64Url;
use Latte\Loaders\StringLoader;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use Nette\DI\Container;
use Nette\DI\InvalidConfigurationException;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use Nette\Routing\Router;
use Raneomik\NettePWA\Action\ManifestAction;
use Raneomik\NettePWA\Action\ServiceWorkerAction;
use Raneomik\NettePWA\Action\SubscribeAction;
use Raneomik\NettePWA\Bridge\Tracy\PWAPanel;
use Raneomik\NettePWA\Bridge\Translator\LocaleResolver;
use Raneomik\NettePWA\Manifest\ManifestInspector;
use Raneomik\NettePWA\Manifest\ManifestProvider;
use Raneomik\NettePWA\Push\Debug\SendLogger;
use Raneomik\NettePWA\Push\Notification;
use Raneomik\NettePWA\Push\PushSender;
use Raneomik\NettePWA\Push\PushServices;
use Raneomik\NettePWA\Push\Storage\DatabaseStorage;
use Raneomik\NettePWA\Push\SubscriptionStorage;
use Raneomik\NettePWA\Push\WebPushFactory;
use Symfony\Component\HttpClient\Psr18Client;
use Tester\Assert;
use Tester\TestCase;
use Tests\Raneomik\NettePWA\Fixtures\ContainerFactory;
use Tests\Raneomik\NettePWA\Fixtures\MemoryStorage;
use Tracy\Bar;

/**
 * @testCase
 */
final class PWAExtensionTest extends TestCase
{
    private const string Router = <<<'NEON'
        services:
            - Nette\Application\Routers\RouteList()::addRoute('<presenter>/<action>', 'Home:default')
        NEON;

    private const string Push = <<<'NEON'
        pwa:
            manifest: {name: Demo}
            push:
                vapid: {publicKey: '', privateKey: '', subject: 'mailto:test@example.com'}
        NEON;

    private const string Authorizer = <<<'NEON'
        services:
            - Tests\Raneomik\NettePWA\Fixtures\PrefixAuthorizer
        NEON;

    public function testManifestOnly(): void
    {
        $container = ContainerFactory::create(<<<'NEON'
            pwa:
                manifest:
                    name: Demo app
                    shortName: Demo
                    themeColor: '#112233'
                    icons:
                        - {src: /icon.svg, sizes: any, type: image/svg+xml}
                    extra:
                        categories: [games]
            NEON
, self::Router);

        Assert::same([
            'name' => 'Demo app',
            'short_name' => 'Demo',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'theme_color' => '#112233',
            'icons' => [[
                'src' => '/icon.svg',
                'sizes' => 'any',
                'type' => 'image/svg+xml',
            ]],
            'categories' => ['games'],
        ], $container->getByType(ManifestProvider::class)->build());

        $router = $container->getByType(Router::class);
        Assert::type(ManifestAction::class, $this->match($router, '/manifest.webmanifest')['callback'] ?? null);
        Assert::type(ServiceWorkerAction::class, $this->match($router, '/sw.js')['callback'] ?? null);
        Assert::same('Pwa', $this->match($router, '/pwa/push')['presenter'] ?? null); // no subscribe route: the app catch-all takes it
        Assert::false($container->hasService('pwa.sender'));
        // route callbacks only: never injected into an app service asking for the type
        Assert::null($container->getByType(ManifestAction::class, false));
        Assert::null($container->getByType(ServiceWorkerAction::class, false));

        // the package's service worker by default
        $sw = $this->match($router, '/sw.js')['callback'] ?? null;
        \assert($sw instanceof ServiceWorkerAction);
        ob_start();
        $sw()->send(new Request(new UrlScript('https://example.com/sw.js')), new \Nette\Http\Response());
        Assert::same(file_get_contents(\dirname(__DIR__, 4) . '/assets/sw.js'), ob_get_clean());
    }

    public function testInvalidManifest(): void
    {
        Assert::exception(fn (): Container => ContainerFactory::create("pwa:\n    manifest: {shortName: D}", self::Router), InvalidConfigurationException::class, '~name~');
        Assert::exception(fn (): Container => ContainerFactory::create("pwa:\n    manifest: {name: D, display: window}", self::Router), InvalidConfigurationException::class, '~display~');
    }

    public function testPush(): void
    {
        $keys = VAPID::createVapidKeys();
        $container = ContainerFactory::create(
            str_replace("publicKey: '', privateKey: ''", "publicKey: '{$keys['publicKey']}', privateKey: '{$keys['privateKey']}'", self::Push),
            self::Router,
            self::Authorizer,
        );

        // generated factory: a fresh WebPush (and its queue) per send
        $factory = $container->getByType(WebPushFactory::class);
        Assert::type(WebPush::class, $first = $factory->create());
        Assert::notSame($first, $factory->create());
        Assert::same(2419200, $first->getDefaultOptions()['TTL']); // schema default
        $vapid = $this->property($first, 'auth')['VAPID'] ?? [];
        Assert::same('mailto:test@example.com', $vapid['subject'] ?? null);
        Assert::same(Base64Url::decode($keys['publicKey']), $vapid['publicKey'] ?? null); // WebPush keeps them decoded

        // per push service call, during the request: a slow one must not hang the page
        $client = $this->property($container->getService('pwa.httpClient'), 'client');
        Assert::same(3.0, $this->property($client, 'defaultOptions')['timeout']);
        Assert::same(3.0, $this->property($client, 'defaultOptions')['max_duration']);
        Assert::null($container->getByType(Psr18Client::class, false), 'the app keeps its own HTTP client');

        Assert::type(DatabaseStorage::class, $container->getByType(SubscriptionStorage::class));
        Assert::null($container->getByType(SubscribeAction::class, false));
        Assert::true($container->getByType(PushServices::class)->accepts('https://fcm.googleapis.com/x'));

        // {pwaHead()} registered in Latte, with the push metas
        $latte = $container->getByType(LatteFactory::class)->create();
        $latte->setLoader(new StringLoader());
        Assert::contains("<meta name=\"pwa-vapid-key\" content=\"{$keys['publicKey']}\">", $latte->renderToString('{pwaHead()}'));
        Assert::type(SubscribeAction::class, $this->match($container->getByType(Router::class), '/pwa/push/subscribe')['callback'] ?? null);
    }

    public function testPushOptions(): void
    {
        $keys = VAPID::createVapidKeys();
        $container = ContainerFactory::create(
            str_replace("publicKey: '', privateKey: ''", "publicKey: '{$keys['publicKey']}', privateKey: '{$keys['privateKey']}'", self::Push) . "\n        ttl: 60\n        urgency: high\n        pushServices: [push.example.com]",
            self::Router,
            self::Authorizer,
        );

        $options = $container->getByType(WebPushFactory::class)->create()->getDefaultOptions();
        Assert::same(60, $options['TTL']);
        Assert::same('high', $options['urgency']);

        // replaces the defaults, does not add to them
        $services = $container->getByType(PushServices::class);
        Assert::true($services->accepts('https://eu.push.example.com/x'));
        Assert::false($services->accepts('https://fcm.googleapis.com/x'));
    }

    public function testPushWithEmptyKeysSendsNothing(): void
    {
        $container = ContainerFactory::create(self::Push, self::Router, self::Authorizer, <<<'NEON'
            services:
                - Tests\Raneomik\NettePWA\Fixtures\MemoryStorage
            NEON);

        Assert::type(MemoryStorage::class, $container->getByType(SubscriptionStorage::class)); // the app's storage wins
        $sender = $container->getByType(PushSender::class);
        Assert::same([], $sender->send(['room/nobody'], new Notification('Hi')));
        Assert::same([], $sender->send([], new Notification('Hi')));
    }

    public function testTheLocaleOfASubscriptionComesFromTheAppTranslator(): void
    {
        $resolver = ContainerFactory::create(self::Push, self::Router, self::Authorizer)->getByType(LocaleResolver::class);
        Assert::same('en', $resolver->getLocale('fr'), 'no translator: english only');

        // registered after "pwa", as an app may do
        $resolver = ContainerFactory::create(self::Push, self::Router, self::Authorizer, <<<'NEON'
            extensions:
                translation: Contributte\Translation\DI\TranslationExtension
            translation:
                locales: {whitelist: [cs, fr], default: cs}
            NEON)->getByType(LocaleResolver::class);
        Assert::same('fr', $resolver->getLocale('fr'));
        Assert::same('cs', $resolver->getLocale('de'));
    }

    public function testSubscribePathIsTheSameForTheRouteAndTheMeta(): void
    {
        $keys = VAPID::createVapidKeys();
        $container = ContainerFactory::create(
            str_replace("publicKey: '', privateKey: ''", "publicKey: '{$keys['publicKey']}', privateKey: '{$keys['privateKey']}'", self::Push) . "\n        subscribeAtPath: '/pwa-subscribe'",
            self::Router,
            self::Authorizer,
        );
        $router = $container->getByType(Router::class);

        // relative to the base path, even with a leading "/": an app in a subdirectory gets both right
        $inSubdirectory = new Request(new UrlScript('https://example.com/app/pwa-subscribe', '/app/'));
        Assert::type(SubscribeAction::class, $router->match($inSubdirectory)['callback'] ?? null);
        Assert::false(($this->match($router, '/pwa/push/subscribe')['callback'] ?? null) instanceof SubscribeAction, 'the default path is replaced');

        $latte = $container->getByType(LatteFactory::class)->create();
        $latte->setLoader(new StringLoader());
        Assert::contains('<meta name="pwa-subscribe-url" content="/pwa-subscribe">', $latte->renderToString('{pwaHead()}'));
    }

    public function testServiceWorkerUrlMayCarryTheSubscribeUrl(): void
    {
        $router = ContainerFactory::create(self::Push, self::Router, self::Authorizer)->getByType(Router::class);
        $url = new UrlScript('https://example.com/sw.js?subscribe=%2Fpwa%2Fpush%2Fsubscribe', '/');

        $params = $router->match(new Request($url)) ?? [];
        Assert::type(ServiceWorkerAction::class, $params['callback'] ?? null);
        // MicroPresenter redirects (301) a GET whose canonical URL differs: the query must survive it
        Assert::same((string) $url, $router->constructUrl($params, $url));
    }

    public function testTracyPanelLogsThePushesInDebugMode(): void
    {
        $container = ContainerFactory::create(
            self::Push . "\n    debugger: true",
            self::Router,
            self::Authorizer,
            "services:\n    - Tests\\Raneomik\\NettePWA\\Fixtures\\MemoryStorage",
        );

        $panel = $this->panel($container);
        Assert::type(SendLogger::class, $container->getService('pwa.sendLogger'));
        Assert::null($container->getByType(SendLogger::class, false));
        Assert::null($container->getByType(ManifestInspector::class, false));

        // the sender records into the very log the panel drains
        $container->getByType(PushSender::class)->send('room/nobody', new Notification('Hi'));
        Assert::match('%A%Package file (assets/sw.js)%A%Hi%A%room/nobody%A%', $panel->getPanel()); // read once: the panel memoizes
    }

    public function testTheDefaultVapidSubjectIsTheBaseUrl(): void
    {
        $container = ContainerFactory::create(
            str_replace(", subject: 'mailto:test@example.com'", '', self::Push) . "\n    debugger: true",
            self::Router,
            self::Authorizer,
        );

        $baseUrl = rtrim($container->getByType(\Nette\Http\IRequest::class)->getUrl()->getBaseUrl(), '/');
        Assert::match("%A%<dt>VAPID subject</dt><dd>{$baseUrl}</dd>%A%", $this->panel($container)->getPanel());
        Assert::notContains('%baseUrl%', $this->panel($container)->getPanel());
    }

    public function testNoPanelOutsideDebugMode(): void
    {
        $container = ContainerFactory::create(self::Push . "\n    debugger: false", self::Router, self::Authorizer);

        Assert::null($container->getByType(Bar::class)->getPanel('pwa'));
        Assert::false($container->hasService('pwa.sendLogger'), 'nothing recorded in production');
    }

    // one process each: the Tracy bar is static, a panel added by one container stays for the next
    public function testThePanelFollowsTheDebugModeByDefault(): void
    {
        $container = ContainerFactory::create("pwa:\n    manifest: {name: Demo}", "parameters:\n    debugMode: true", self::Router);

        Assert::type(PWAPanel::class, $container->getByType(Bar::class)->getPanel('pwa'));
    }

    public function testNoPanelOutsideTheDebugModeByDefault(): void
    {
        $container = ContainerFactory::create("pwa:\n    manifest: {name: Demo}", "parameters:\n    debugMode: false", self::Router);

        Assert::null($container->getByType(Bar::class)->getPanel('pwa'));
    }

    public function testPanelWithoutPush(): void
    {
        $container = ContainerFactory::create("pwa:\n    manifest: {name: Demo}\n    debugger: true", self::Router);

        $panel = $this->panel($container);
        Assert::contains('Not configured (pwa: push).', $panel->getPanel());
    }

    private function panel(Container $container): PWAPanel
    {
        $panel = $container->getByType(Bar::class)->getPanel('pwa');
        if (! $panel instanceof PWAPanel) {
            throw new \LogicException('The PWA panel is not on the Tracy bar.');
        }

        return $panel;
    }

    public function testPushWithoutTopicAuthorizerIsRefused(): void
    {
        Assert::exception(
            fn (): Container => ContainerFactory::create(self::Push, self::Router),
            InvalidConfigurationException::class,
            '~TopicAuthorizer~',
        );
    }

    private function property(object $object, string $name): mixed
    {
        return (fn (): mixed => $this->{$name})->call($object);
    }

    /**
     * @return ?array<string, mixed>
     */
    private function match(Router $router, string $path): ?array
    {
        return $router->match(new Request(new UrlScript("https://example.com{$path}", '/')));
    }
}

(new PWAExtensionTest())->run();
