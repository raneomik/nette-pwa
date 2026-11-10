<?php

declare(strict_types=1);

namespace Raneomik\NettePWA\Manifest\Value;

/**
 * The NEON "manifest" section, built by PWAExtension's config schema (same defaults).
 * Members the inspector does not check stay raw in $extra: no need to model the whole spec.
 */
final readonly class Manifest implements \JsonSerializable
{
    /**
     * @param list<Icon> $icons
     * @param array<string, mixed> $extra raw manifest members (shortcuts, screenshots, categories...), merged as is
     */
    public function __construct(
        public string $name,
        public ?string $shortName = null,
        public ?string $description = null,
        public ?string $id = null,
        public string $startUrl = '/',
        public string $scope = '/',
        public Display $display = Display::Standalone,
        public ?string $orientation = null,
        public ?string $lang = null,
        public ?string $backgroundColor = null,
        public ?string $themeColor = null,
        public array $icons = [],
        public array $extra = [],
    ) {
    }

    /**
     * @return array<string, mixed> the members win over $extra
     */
    public function jsonSerialize(): array
    {
        return array_filter([
            'name' => $this->name,
            'short_name' => $this->shortName,
            'description' => $this->description,
            'id' => $this->id,
            'start_url' => $this->startUrl,
            'scope' => $this->scope,
            'display' => $this->display->value,
            'orientation' => $this->orientation,
            'lang' => $this->lang,
            'background_color' => $this->backgroundColor,
            'theme_color' => $this->themeColor,
            'icons' => $this->icons,
        ], static fn (mixed $value): bool => null !== $value) + $this->extra;
    }
}
