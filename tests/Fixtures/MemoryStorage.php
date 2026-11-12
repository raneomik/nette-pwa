<?php

declare(strict_types=1);

namespace Tests\Raneomik\NettePWA\Fixtures;

use Minishlink\WebPush\Subscription;
use Raneomik\NettePWA\Push\SubscriptionStorage;

final class MemoryStorage implements SubscriptionStorage
{
    /**
     * @var array<string, array{Subscription, list<string>, string}> endpoint => [subscription, topics, locale]
     */
    public array $rows = [];

    public function save(Subscription $subscription, array $topics, string $locale = 'en'): void
    {
        $this->rows[$subscription->getEndpoint()] = [$subscription, $topics, $locale];
    }

    public function find(array $topics): iterable
    {
        $byLocale = [];
        foreach ($this->rows as [$subscription, $rowTopics, $locale]) {
            if ([] !== array_intersect($rowTopics, $topics)) {
                $byLocale[$locale][] = $subscription;
            }
        }

        return $byLocale;
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

        [,$topics, $locale] = $this->rows[$oldEndpoint];
        unset($this->rows[$oldEndpoint]);
        $this->rows[$subscription->getEndpoint()] = [$subscription, $topics, $locale];

        return true;
    }
}
