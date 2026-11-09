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
use Raneomik\NettePWA\Manifest\ManifestProvider;
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
                'display' => Expect::anyOf('fullscreen', 'standalone', 'minimal-ui', 'browser')->default('standalone'),
                'orientation' => Expect::string(),
                'lang' => Expect::string(),
                'backgroundColor' => Expect::string(),
                'themeColor' => Expect::string(),
                'icons' => Expect::listOf(Expect::structure([
                    'src' => Expect::string()->required(), // "img:icon" (Nette Assets) or a URL
                    'sizes' => Expect::string(),
                    'type' => Expect::string(),
                    'purpose' => Expect::string(),
                ])->castTo('array')),
                'extra' => Expect::array(), // raw manifest members, merged as is
            ])->castTo('array')->required(),
            'serviceWorker' => Expect::string(\dirname(__DIR__, 3) . '/assets/sw.js'),
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
                'subscribeAtPath' => Expect::string()->default('/pwa/push/subscribe'),
            ]))->default(null), // null = no push services, no subscribe route
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
                'defaultOptions' => array_filter([
                    'TTL' => $push->ttl,
                    'urgency' => $push->urgency,
                ], static fn (mixed $value): bool => null !== $value),
                'client' => new Reference($this->prefix('httpClient')),
                'requestFactory' => new Reference($this->prefix('httpClient')),
                'streamFactory' => new Reference($this->prefix('httpClient')),
            ]);

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

            $routes[$config->push->subscribeAtPath] = 'subscribeAction';
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

        if ($builder->hasDefinition('latte.latteFactory')) {
            $latte = $builder->getDefinition('latte.latteFactory');
            \assert($latte instanceof Nette\DI\Definitions\FactoryDefinition);
            $latte->getResultDefinition()
                ->addSetup('addExtension', [
                    new Statement(PWALatteExtension::class, [
                        'vapidPublicKey' => $config->push?->vapid->publicKey,
                    ]),
                ]);
        }
    }
}
