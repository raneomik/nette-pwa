<?php

declare(strict_types=1);

namespace Raneomik\NettePWA\Push\Storage;

use Minishlink\WebPush\Subscription;
use Nette\Database\Explorer;
use Raneomik\NettePWA\Push\SubscriptionStorage;

/**
 * One row per (endpoint, topic), table in README.
 * shortcut: the keys are repeated on each topic row; split into two tables if topics per browser grow large
 */
final readonly class DatabaseStorage implements SubscriptionStorage
{
    public function __construct(
        private Explorer $database,
        private string $table = 'push_subscriptions',
    ) {
    }

    public function save(Subscription $subscription, array $topics): void
    {
        $now = time();
        $this->database->transaction(function () use ($subscription, $topics, $now): void {
            $this->remove($subscription->getEndpoint());
            $this->database->query('INSERT INTO ?name', $this->table, array_map(static fn (string $topic): array => [
                'endpoint' => $subscription->getEndpoint(),
                'topic' => $topic,
                'p256dh' => $subscription->getPublicKey(),
                'auth' => $subscription->getAuthToken(),
                'createdAt' => $now,
            ], array_unique($topics)));
        });
    }

    public function findByTopics(array $topics): iterable
    {
        $rows = $this->database->table($this->table)
            ->select('endpoint, p256dh, auth')
            ->where('topic', $topics)
            ->group('endpoint, p256dh, auth');
        foreach ($rows as $row) {
            yield new Subscription((string) $row['endpoint'], (string) $row['p256dh'], (string) $row['auth']);
        }
    }

    public function remove(string $endpoint): void
    {
        $this->database->table($this->table)->where('endpoint', $endpoint)->delete();
    }
}
