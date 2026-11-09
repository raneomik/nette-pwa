<?php

declare(strict_types=1);

namespace Raneomik\NettePWA\Push;

use Minishlink\WebPush\Subscription;

/**
 * A subscription (= a browser, identified by its endpoint) listens to topics, as with Mercure.
 */
interface SubscriptionStorage
{
    /**
     * replaces the topics of this endpoint: a browser listens to what its last page asked for
     *
     * @param non-empty-list<string> $topics
     */
    public function save(Subscription $subscription, array $topics): void;

    /**
     * @param non-empty-list<string> $topics
     * @return iterable<Subscription> each endpoint once, even if it listens to several of them
     */
    public function findByTopics(array $topics): iterable;

    public function remove(string $endpoint): void;
}
