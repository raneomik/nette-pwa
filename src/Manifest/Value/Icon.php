<?php

declare(strict_types=1);

namespace Raneomik\NettePWA\Manifest\Value;

final readonly class Icon implements \JsonSerializable
{
    /**
     * @param string $src "img:icon" = Nette Assets reference, "/icon.svg" or "https://..." = URL
     */
    public function __construct(
        public string $src,
        public ?string $sizes = null,
        public ?string $type = null,
        public ?string $purpose = null,
    ) {
    }

    public function isAssetReference(): bool
    {
        return ! str_starts_with($this->src, '/') && ! str_contains($this->src, '://');
    }

    /**
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        return array_filter([
            'src' => $this->src,
            'sizes' => $this->sizes,
            'type' => $this->type,
            'purpose' => $this->purpose,
        ], static fn (?string $value): bool => null !== $value);
    }
}
