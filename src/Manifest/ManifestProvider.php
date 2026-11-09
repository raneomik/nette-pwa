<?php

declare(strict_types=1);

namespace Raneomik\NettePWA\Manifest;

use Nette\Assets\Registry;
use Nette\Utils\Json;

/**
 * Builds the web app manifest JSON from the NEON "manifest" section.
 * Built once per container (= once per worker with FrankenPHP): the configuration is compiled in.
 */
final class ManifestProvider
{
    /**
     * NEON key => manifest member
     */
    private const array Members = [
        'name' => 'name',
        'shortName' => 'short_name',
        'description' => 'description',
        'id' => 'id',
        'startUrl' => 'start_url',
        'scope' => 'scope',
        'display' => 'display',
        'orientation' => 'orientation',
        'lang' => 'lang',
        'backgroundColor' => 'background_color',
        'themeColor' => 'theme_color',
    ];

    private ?string $json = null;

    /**
     * @param array<string, mixed> $config validated by PWALatteExtension::getConfigSchema()
     */
    public function __construct(
        private readonly array $config,
        private readonly ?Registry $assets = null,
    ) {
    }

    public function getThemeColor(): ?string
    {
        return $this->config['themeColor'] ?? null;
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
        $manifest = [];
        foreach (self::Members as $key => $member) {
            if (null !== ($this->config[$key] ?? null)) {
                $manifest[$member] = $this->config[$key];
            }
        }

        $manifest['icons'] = array_map(
            fn (array $icon): array => [
                'src' => $this->url($icon['src']),
            ] + array_filter(
                $icon,
                static fn (mixed $value, string $key): bool => 'src' !== $key && null !== $value,
                \ARRAY_FILTER_USE_BOTH,
            ),
            $this->config['icons'] ?? [],
        );

        // raw members (shortcuts, screenshots, categories...): no need to model the whole spec
        return $manifest + ($this->config['extra'] ?? []);
    }

    /**
     * "img:icon" = Nette Assets reference (versioned URL), "/icon.svg" or "https://..." = as is
     */
    private function url(string $src): string
    {
        if (str_starts_with($src, '/')
            || str_contains($src, '://')
            || false === $this->assets instanceof Registry
        ) {
            return $src;
        }

        return (string) $this->assets->getAsset($src);
    }
}
