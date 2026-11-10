<?php

declare(strict_types=1);

namespace Raneomik\NettePWA\Manifest;

use Nette\Assets\Registry;
use Nette\Utils\Json;
use Raneomik\NettePWA\Manifest\Value\Icon;
use Raneomik\NettePWA\Manifest\Value\Manifest;

/**
 * Publishes the manifest as JSON, icon asset references resolved to their (versioned) URLs.
 * Built once per container (= once per worker with FrankenPHP): the configuration is compiled in.
 */
final class ManifestProvider
{
    private ?string $json = null;

    public function __construct(
        public readonly Manifest $manifest,
        private readonly ?Registry $assets = null,
    ) {
    }

    public function getJson(): string
    {
        return $this->json ??= Json::encode($this->build(), pretty: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $manifest = $this->manifest->jsonSerialize();
        $manifest['icons'] = array_map(
            fn (Icon $icon): array => [
                'src' => $icon->isAssetReference() && $this->assets instanceof Registry
                    ? (string) $this->assets->getAsset($icon->src)
                    : $icon->src, // without Nette Assets, "img:icon" is kept as is
            ] + $icon->jsonSerialize(),
            $this->manifest->icons,
        );

        return $manifest;
    }
}
