<?php

declare(strict_types=1);

namespace Tests\Raneomik\NettePWA\Integration\Push\Storage;

require \dirname(__DIR__, 3) . '/bootstrap.php';

use Minishlink\WebPush\Subscription;
use Nette\Database\Explorer;
use Raneomik\NettePWA\Push\SubscriptionStorage;
use Tester\Assert;
use Tester\TestCase;
use Tests\Raneomik\NettePWA\Fixtures\ContainerFactory;

/**
 * @testCase
 */
final class DatabaseStorageTest extends TestCase
{
    private SubscriptionStorage $storage;

    protected function setUp(): void
    {
        $container = ContainerFactory::create(<<<'NEON'
            pwa:
                manifest: {name: Demo}
                push:
                    vapid: {publicKey: '', privateKey: '', subject: 'mailto:test@example.com'}
                    table: subs
            services:
                - Nette\Application\Routers\RouteList
                - Tests\Raneomik\NettePWA\Fixtures\PrefixAuthorizer
            NEON);
        $container->getByType(Explorer::class)->query(
            <<<'SQL'
            CREATE TABLE subs (
                endpoint TEXT NOT NULL,
                topic TEXT NOT NULL,
                locale varchar(64) NOT NULL,
                p256dh TEXT NOT NULL,
                auth TEXT NOT NULL,
                createdAt INTEGER NOT NULL,
                PRIMARY KEY (endpoint, topic)
              )
            SQL
        );
        $this->storage = $container->getByType(SubscriptionStorage::class);
    }

    public function testSaveReplacesTheTopicsOfAnEndpoint(): void
    {
        $this->storage->save(new Subscription('https://push/1', 'key', 'auth'), ['room/a', 'player/1'], 'en');
        $this->storage->save(new Subscription('https://push/1', 'key2', 'auth2'), ['room/b', 'player/1', 'player/1'], 'en'); // duplicates ignored

        Assert::same([], $this->find('room/a'));
        Assert::same([['https://push/1', 'key2', 'auth2']], $this->find('player/1'));
    }

    public function testEachEndpointFoundOnce(): void
    {
        $this->storage->save(new Subscription('https://push/1', 'key', 'auth'), ['room/b', 'player/1'], 'en');
        $this->storage->save(new Subscription('https://push/2', 'key', 'auth'), ['room/b'], 'en');

        Assert::same(['https://push/1', 'https://push/2'], array_column($this->find('room/b', 'player/1'), 0));
    }

    public function testFoundByLocale(): void
    {
        $this->storage->save(new Subscription('https://push/1', 'key', 'auth'), ['room/b'], 'en');
        $this->storage->save(new Subscription('https://push/2', 'key', 'auth'), ['room/b'], 'fr');

        Assert::equal([
            'en' => ['https://push/1'],
            'fr' => ['https://push/2'],
        ], array_map(
            static fn (array $subscriptions): array => array_map(static fn (Subscription $s): string => $s->getEndpoint(), $subscriptions),
            [...$this->storage->find(['room/b'])],
        ));
    }

    public function testRemove(): void
    {
        $this->storage->save(new Subscription('https://push/1', 'key', 'auth'), ['room/b'], 'en');
        $this->storage->save(new Subscription('https://push/2', 'key', 'auth'), ['room/b'], 'en');
        $this->storage->remove('https://push/1');

        Assert::same(['https://push/2'], array_column($this->find('room/b'), 0));
    }

    public function testReplaceMovesTheTopicsToTheNewEndpoint(): void
    {
        $this->storage->save(new Subscription('https://push/1', 'key', 'auth'), ['room/a', 'player/1'], 'fr');
        $this->storage->save(new Subscription('https://push/2', 'key', 'auth'), ['room/b'], 'en'); // stale rows of the new endpoint

        Assert::true($this->storage->replace('https://push/1', new Subscription('https://push/2', 'key2', 'auth2')));

        Assert::same([['https://push/2', 'key2', 'auth2']], $this->find('room/a', 'player/1'));
        Assert::same([], $this->find('room/b'), 'the new endpoint gets the topics of the old one only');
        Assert::same(['fr'], array_keys([...$this->storage->find(['room/a', 'player/1'])]), 'and its locale');
    }

    public function testReplaceKeepsTheEndpointWhenOnlyTheKeysChange(): void
    {
        $this->storage->save(new Subscription('https://push/1', 'key', 'auth'), ['room/a'], 'en');

        Assert::true($this->storage->replace('https://push/1', new Subscription('https://push/1', 'key2', 'auth2')));
        Assert::same([['https://push/1', 'key2', 'auth2']], $this->find('room/a'));
    }

    public function testReplaceAnUnknownEndpoint(): void
    {
        $this->storage->save(new Subscription('https://push/2', 'key', 'auth'), ['room/b'], 'en');

        Assert::false($this->storage->replace('https://push/1', new Subscription('https://push/2', 'key2', 'auth2')));
        Assert::same([['https://push/2', 'key', 'auth']], $this->find('room/b'), 'nothing removed when there is nothing to move');
    }

    /**
     * @return list<array{string, ?string, ?string}>
     */
    private function find(string $topic, string ...$more): array
    {
        return array_map(
            static fn (Subscription $s): array => [$s->getEndpoint(), $s->getPublicKey(), $s->getAuthToken()],
            array_merge(...array_values([...$this->storage->find([$topic, ...array_values($more)])])),
        );
    }
}

(new DatabaseStorageTest())->run();
