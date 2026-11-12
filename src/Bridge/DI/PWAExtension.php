<?php

declare(strict_types=1);

namespace Raneomik\NettePWA\Bridge\DI;

use Minishlink\WebPush\WebPush;
use Nette;
use Nette\Application\Routers\Route;
use Nette\DI\Definitions\Reference;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\DI\Definitions\Statement;
use Nette\Schema\Expect;
use Raneomik\NettePWA\Action\ManifestAction;
use Raneomik\NettePWA\Action\ServiceWorkerAction;
use Raneomik\NettePWA\Action\SubscribeAction;
use Raneomik\NettePWA\Bridge\Latte\PWALatteExtension;
use Raneomik\NettePWA\Bridge\Tracy\PWAPanel;
use Raneomik\NettePWA\Bridge\Translator\LocaleResolver;
use Raneomik\NettePWA\Manifest\ManifestInspector;
use Raneomik\NettePWA\Manifest\ManifestProvider;
use Raneomik\NettePWA\Manifest\Value\Display;
use Raneomik\NettePWA\Manifest\Value\Icon;
use Raneomik\NettePWA\Manifest\Value\Manifest;
use Raneomik\NettePWA\Push\Debug\SendLogger;
use Raneomik\NettePWA\Push\PushSender;
use Raneomik\NettePWA\Push\PushServices;
use Raneomik\NettePWA\Push\Storage\DatabaseStorage;
use Raneomik\NettePWA\Push\SubscriptionStorage;
use Raneomik\NettePWA\Push\TopicAuthorizer;
use Raneomik\NettePWA\Push\WebPushFactory;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * pwa:
 *     manifest: {name: ..., icons: [...]}     # served at /manifest.webmanifest
 *     serviceWorker: %appDir%/../assets/sw.js # optional, served at /sw.js
 *     push: {vapid: {publicKey: ..., privateKey: ...}} # optional: Web Push + /pwa/push/subscribe
 *     debugger: true                          # Tracy panel, default %debugMode%
 */
final class PWAExtension extends Nette\DI\CompilerExtension
{
    #[\Override]
    public function getConfigSchema(): Nette\Schema\Schema
    {
        return Expect::structure([
            'manifest' => Expect::structure([
                'name' => Expect::string()->required(),
                'shortName' => Expect::string(),
                'description' => Expect::string(),
                'id' => Expect::string(),
                'startUrl' => Expect::string('/'),
                'scope' => Expect::string('/'),
                'display' => Expect::enum(Display::class)->default(Display::Standalone),
                'orientation' => Expect::string(),
                'lang' => Expect::string(),
                'backgroundColor' => Expect::string(),
                'themeColor' => Expect::string(),
                'icons' => Expect::listOf(Expect::structure([
                    'src' => Expect::string()->required(), // "img:icon" (Nette Assets) or a URL
                    'sizes' => Expect::string(),
                    'type' => Expect::string(),
                    'purpose' => Expect::string(),
                ])->castTo(Icon::class)),
                'extra' => Expect::array(), // raw manifest members, merged as is
            ])->castTo(Manifest::class)->required(),
            'serviceWorker' => Expect::string($this->packageServiceWorker()),
            'push' => Expect::anyOf(null, Expect::structure([
                'vapid' => Expect::structure([
                    // empty = push off: {pwaHead()} hides the opt-in, nothing can subscribe
                    'publicKey' => Expect::string()->required()->dynamic(),
                    'privateKey' => Expect::string()->required()->dynamic(),
                    'subject' => Expect::string('%baseUrl%')->dynamic(), // mailto: or https://
                ]),
                'ttl' => Expect::int(2419200), // seconds the push service keeps it for an offline device
                'urgency' => Expect::anyOf('very-low', 'low', 'normal', 'high')->nullable(),
                'timeout' => Expect::float(3.0), // per push service call, during the request
                'table' => Expect::string('push_subscriptions'), // default storage only
                'pushServices' => Expect::listOf('string')->default(PushServices::Defaults)->mergeDefaults(false),
                'subscribeAtPath' => Expect::string()->default(SubscribeAction::DefaultPath), // POST subscribe, PUT rotate, DELETE unsubscribe
            ]))->default(null), // null = no push services, no subscribe route
            'debugger' => Expect::bool()->nullable(), // Tracy panel; null = %debugMode%
        ]);
    }

    public function loadConfiguration(): void
    {
        $builder = $this->getContainerBuilder();
        /** @var \stdClass $config */
        $config = $this->getConfig();

        $builder->addDefinition($this->prefix('manifest'))
            ->setCreator(ManifestProvider::class, [
                $config->manifest,
            ]);
        $builder->addDefinition($this->prefix('manifestAction'))
            ->setCreator(ManifestAction::class)
            ->setAutowired(false);
        $builder->addDefinition($this->prefix('serviceWorkerAction'))
            ->setCreator(ServiceWorkerAction::class, [$config->serviceWorker])
            ->setAutowired(false);

        if (null === $config->push) {
            return;
        }

        $push = $config->push;
        // a schema default is not expanded like the app's own config: '%baseUrl%' (a dynamic parameter) would stay literal
        $push->vapid->subject = Nette\DI\Helpers::expand($push->vapid->subject, $builder->parameters);

        $builder->addDefinition($this->prefix('httpClient'))
            ->setCreator(Psr18Client::class, [
                new Statement([HttpClient::class, 'create'], [[
                    'timeout' => $push->timeout,
                    'max_duration' => $push->timeout,
                ]]),
            ])
            ->setAutowired(false);

        // a generated factory, not a WebPush service: see WebPushFactory
        $builder->addFactoryDefinition($this->prefix('webPushFactory'))
            ->setImplement(WebPushFactory::class)
            ->getResultDefinition()
            ->setCreator(WebPush::class, [
                'auth' => [
                    'VAPID' => [
                        'subject' => $push->vapid->subject,
                        'publicKey' => $push->vapid->publicKey,
                        'privateKey' => $push->vapid->privateKey,
                    ],
                ],
                'defaultOptions' => [
                    // WebPush::setDefaultOptions() takes a null urgency as unset
                    'TTL' => $push->ttl,
                    'urgency' => $push->urgency,
                ],
                'client' => new Reference($this->prefix('httpClient')),
                'requestFactory' => new Reference($this->prefix('httpClient')),
                'streamFactory' => new Reference($this->prefix('httpClient')),
            ]);

        // autowired: the app's translator, null without contributte/translation
        $builder->addDefinition($this->prefix('localeResolver'))
            ->setCreator(LocaleResolver::class);
        $builder->addDefinition($this->prefix('pushServices'))
            ->setCreator(PushServices::class, [$push->pushServices]);
        $builder->addDefinition($this->prefix('sender'))
            ->setCreator(PushSender::class);
        $builder->addDefinition($this->prefix('subscribeAction'))
            ->setCreator(SubscribeAction::class)
            ->setAutowired(false);
    }

    public function beforeCompile(): void
    {
        $builder = $this->getContainerBuilder();
        /** @var \stdClass $config */
        $config = $this->getConfig();

        $routes = [
            'manifest.webmanifest' => 'manifestAction',
            'sw.js' => 'serviceWorkerAction',
        ];

        if (null !== $config->push) {
            // the app's own implementations win; defaults otherwise
            if ([] === $builder->findByType(SubscriptionStorage::class)) {
                $builder->addDefinition($this->prefix('storage'))
                    ->setCreator(DatabaseStorage::class, [
                        'table' => $config->push->table,
                    ]);
            }

            // no safe default: "allow all" would let anyone receive anyone's pushes
            if ([] === $builder->findByType(TopicAuthorizer::class)) {
                throw new Nette\DI\InvalidConfigurationException(\sprintf(
                    "'%s: push' needs a %s service: which topics a visitor may subscribe to.",
                    $this->name,
                    TopicAuthorizer::class,
                ));
            }

            $routes[ltrim($config->push->subscribeAtPath, '/')] = 'subscribeAction';
        }

        // before the app's routes: a catch-all "<presenter>/<action>" would take them.
        // shortcut: needs a RouteList router (any app with routes); Nette's fallback SimpleRouter has no prepend()
        $router = $builder->getDefinitionByType(Nette\Routing\Router::class);
        \assert($router instanceof ServiceDefinition);

        foreach ($routes as $mask => $action) {
            // "?->prepend()": the definition may be typed as the Router interface, which lacks prepend()
            $router->addSetup('?->prepend(?)', [
                '@self',
                new Statement(Route::class, [$mask, [
                    'presenter' => 'Nette:Micro',
                    'callback' => new Reference($this->prefix($action)),
                ]]),
            ]);
        }

        $this->registerPanel($config);

        if ($builder->hasDefinition('latte.latteFactory')) {
            $latte = $builder->getDefinition('latte.latteFactory');
            \assert($latte instanceof Nette\DI\Definitions\FactoryDefinition);
            $latte->getResultDefinition()
                ->addSetup('addExtension', [
                    new Statement(PWALatteExtension::class, [
                        'manifest' => $this->manifest(),
                    ] + (null === $config->push ? [] : [
                        'vapidPublicKey' => $config->push->vapid->publicKey,
                        'subscribePath' => $config->push->subscribeAtPath,
                    ])),
                ]);
        }
    }

    /**
     * PWA Tracy panel; the push log only exists with it, PushSender records nothing otherwise
     */
    private function registerPanel(\stdClass $config): void
    {
        $builder = $this->getContainerBuilder();
        $enabled = $config->debugger ?? ! empty($builder->parameters['debugMode']);
        if (! $enabled || ! $builder->hasDefinition('tracy.bar')) { // tracy.bar: Tracy installed and registered
            return;
        }

        $builder->addDefinition($this->prefix('manifestInspector'))
            ->setCreator(ManifestInspector::class, [
                'manifest' => $this->manifest(),
                'wwwDir' => $builder->parameters['wwwDir'] ?? null,
            ])
            ->setAutowired(false);

        $push = null;
        if (null !== $config->push) {
            $builder->addDefinition($this->prefix('sendLogger'))
                ->setCreator(SendLogger::class)
                ->setAutowired(false);
            $sender = $builder->getDefinition($this->prefix('sender'));
            \assert($sender instanceof ServiceDefinition);
            $sender->setArguments([
                'logger' => new Reference($this->prefix('sendLogger')),
            ]);

            $push = [
                'publicKey' => $config->push->vapid->publicKey,
                'subject' => $config->push->vapid->subject,
                'pushServices' => $config->push->pushServices,
                'subscribePath' => $config->push->subscribeAtPath,
            ];
        }

        $bar = $builder->getDefinition('tracy.bar');
        \assert($bar instanceof ServiceDefinition);
        $bar->addSetup('addPanel', [
            new Statement(PWAPanel::class, [
                'inspector' => new Reference($this->prefix('manifestInspector')),
                'serviceWorker' => $config->serviceWorker,
                'customServiceWorker' => $config->serviceWorker !== $this->packageServiceWorker(),
                'push' => $push,
                'logger' => null === $push ? null : new Reference($this->prefix('sendLogger')),
            ]),
            'pwa',
        ]);
    }

    /**
     * the provider's Manifest: compiled into the container once, shared
     */
    private function manifest(): Statement
    {
        return new Statement([new Reference($this->prefix('manifest')), '$manifest']);
    }

    private function packageServiceWorker(): string
    {
        return \dirname(__DIR__, 3) . '/assets/sw.js';
    }
}
