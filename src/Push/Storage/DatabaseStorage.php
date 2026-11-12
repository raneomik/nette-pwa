<?php

declare(strict_types=1);

namespace Raneomik\NettePWA\Push\Storage;

use Minishlink\WebPush\Subscription;
use Nette\Database\Explorer;
use Nette\Database\Row;
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

    public function save(Subscription $subscription, array $topics, string $locale): void
    {
        $now = time();
        $this->database->transaction(function () use ($subscription, $topics, $locale, $now): void {
            $this->remove($subscription->getEndpoint());
            $this->database->query('INSERT INTO ?name', $this->table, array_map(static fn (string $topic): array => [
                'endpoint' => $subscription->getEndpoint(),
                'topic' => $topic,
                'locale' => $locale,
                'p256dh' => $subscription->getPublicKey(),
                'auth' => $subscription->getAuthToken(),
                'createdAt' => $now,
            ], array_unique($topics)));
        });
    }

    public function find(array $topics): iterable
    {
        $rows = $this->database->table($this->table)
            ->select('endpoint, p256dh, auth, locale')
            ->where('topic', $topics)
            ->group('endpoint, p256dh, auth, locale');
        $byLocale = [];
        foreach ($rows as $row) {
            $byLocale[$row['locale']][] = new Subscription((string) $row['endpoint'], (string) $row['p256dh'], (string) $row['auth']);
        }

        return $byLocale;
    }

    public function remove(string $endpoint): void
    {
        $this->database->table($this->table)->where('endpoint', $endpoint)->delete();
    }

    public function replace(string $oldEndpoint, Subscription $subscription): bool
    {
        return $this->database->transaction(function () use ($oldEndpoint, $subscription): bool {
            if (! $this->database->query('SELECT 1 FROM ?name WHERE endpoint = ?', $this->table, $oldEndpoint)->fetch() instanceof Row) {
                return false; // checked first: nothing of the new endpoint is removed when there is nothing to move
            }

            if ($oldEndpoint !== $subscription->getEndpoint()) {
                $this->remove($subscription->getEndpoint()); // (endpoint, topic) is the primary key
            }

            $this->database->table($this->table)
                ->where('endpoint', $oldEndpoint)
                ->update([
                    'endpoint' => $subscription->getEndpoint(),
                    'p256dh' => $subscription->getPublicKey(),
                    'auth' => $subscription->getAuthToken(),
                    'createdAt' => time(),
                ]);

            return true;
        });
    }
}
