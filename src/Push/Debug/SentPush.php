<?php

declare(strict_types=1);

namespace Raneomik\NettePWA\Push\Debug;

use Raneomik\NettePWA\Push\Notification;

/**
 * One PushSender::send() call, as the Tracy panel shows it.
 */
final readonly class SentPush
{
    /**
     * @param list<string> $topics
     * @param list<string> $expired push service hosts of the removed subscriptions
     * @param list<array{service: string, status: ?int, reason: string}> $failures
     * @param list<string> $locales of the recipients; $notification is the one of the first
     */
    public function __construct(
        public array $topics,
        public Notification $notification,
        public int $recipients,
        public int $delivered,
        public array $expired,
        public array $failures,
        public float $milliseconds,
        public array $locales = [],
    ) {
    }
}
