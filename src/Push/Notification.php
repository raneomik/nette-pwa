<?php

declare(strict_types=1);

namespace Raneomik\NettePWA\Push;

/**
 * Payload read by assets/sw.js ("push" event): keep both in sync.
 */
final readonly class Notification implements \JsonSerializable
{
    /**
     * @param ?string $url opened on click (focused if a tab already shows it)
     * @param ?string $tag a newer notification with the same tag replaces the previous one
     */
    public function __construct(
        public string $title,
        public ?string $body = null,
        public ?string $url = null,
        public ?string $tag = null,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        return array_filter(get_object_vars($this), static fn (?string $value): bool => null !== $value);
    }
}
