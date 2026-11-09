<?php

declare(strict_types=1);

namespace Tests\Raneomik\NettePWA\Fixtures;

use Raneomik\NettePWA\Push\TopicAuthorizer;

final readonly class PrefixAuthorizer implements TopicAuthorizer
{
    public function __construct(
        private string $prefix = 'room/',
    ) {
    }

    public function isAllowed(string $topic): bool
    {
        return str_starts_with($topic, $this->prefix);
    }
}
