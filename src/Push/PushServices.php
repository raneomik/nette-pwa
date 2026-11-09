<?php

declare(strict_types=1);

namespace Raneomik\NettePWA\Push;

/**
 * The server POSTs to the endpoint the browser sent: accept only real push services,
 * never an internal URL (SSRF).
 */
final readonly class PushServices
{
    public const array Defaults = ['fcm.googleapis.com', 'push.services.mozilla.com', 'push.apple.com', 'notify.windows.com'];

    /**
     * @param list<string> $hosts a host also accepts its subdomains
     */
    public function __construct(
        private array $hosts = self::Defaults,
    ) {
    }

    public function accepts(string $endpoint): bool
    {
        $url = parse_url($endpoint);
        $host = strtolower($url['host'] ?? '');
        if ('https' !== ($url['scheme'] ?? null) || '' === $host) {
            return false;
        }

        foreach ($this->hosts as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }
}
