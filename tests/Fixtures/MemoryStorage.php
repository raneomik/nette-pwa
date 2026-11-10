<?php

declare(strict_types=1);

namespace Tests\Raneomik\NettePWA\Fixtures;

use Minishlink\WebPush\Subscription;
use Raneomik\NettePWA\Push\SubscriptionStorage;

final class MemoryStorage implements SubscriptionStorage
{
    /**
     * @var array<string, array{Subscription, list<string>}> endpoint => [subscription, topics]
     */
    public array $rows = [];

    public function save(Subscription $subscription, array $topics): void
    {
        $this->rows[$subscription->getEndpoint()] = [$subscription, $topics];
    }

    public function findByTopics(array $topics): iterable
    {
        foreach ($this->rows as [$subscription, $rowTopics]) {
            if ([] !== array_intersect($rowTopics, $topics)) {
                yield $subscription;
            }
        }
    }

    public function remove(string $endpoint): void
    {
        unset($this->rows[$endpoint]);
    }

    public function replace(string $oldEndpoint, Subscription $subscription): bool
    {
        if (! isset($this->rows[$oldEndpoint])) {
            return false;
        }

        $topics = $this->rows[$oldEndpoint][1];
        unset($this->rows[$oldEndpoint]);
        $this->rows[$subscription->getEndpoint()] = [$subscription, $topics];

        return true;
    }
}
