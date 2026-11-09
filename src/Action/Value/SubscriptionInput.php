<?php

namespace Raneomik\NettePWA\Action\Value;

final readonly class SubscriptionInput
{
    private const string Key = '~^[A-Za-z0-9_-]{16,128}={0,2}$~D'; // base64url

    private function __construct(
        public string $endpoint,
        public string $p256dh,
        public string $auth,
    ) {
    }

    /**
     * @param false|array<mixed> $input untrusted JSON, validated below
     */
    public static function fromInput(false|array $input): self
    {
        if (false === $input) {
            throw new \InvalidArgumentException("Missing input at 'subscription'.");
        }

        $endpoint = $input['endpoint'] ?? null;
        $p256dh = $input['keys']['p256dh'] ?? null;
        $auth = $input['keys']['auth'] ?? null;

        if (! \is_string($endpoint) ) {
            throw new \InvalidArgumentException('Endpoint must be a string.');
        }

        if (! \is_string($p256dh) || 1 !== preg_match(self::Key, $p256dh)) {
            throw new \InvalidArgumentException('p256dh must be a string & in base64url format.');
        }

        if (! \is_string($auth) || 1 !== preg_match(self::Key, $auth)) {
            throw new \InvalidArgumentException('auth must be a string & in base64url format.');
        }

        return new self($endpoint, $p256dh, $auth);
    }
}
