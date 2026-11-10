<?php

declare(strict_types=1);

namespace Raneomik\NettePWA\Push;

/**
 * May the current visitor receive the pushes of this topic? Asked for each topic sent to
 * /pwa/push/subscribe. Same question as Mercure's subscribe authorization: the app usually
 * answers both with one service. No default: the app must register one (PWAExtension checks).
 */
interface TopicAuthorizer
{
    public function isAllowed(string $topic): bool;
}
