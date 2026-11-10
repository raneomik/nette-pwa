<?php

declare(strict_types=1);

namespace Raneomik\NettePWA\Manifest;

use Nette\Assets\ImageAsset;
use Nette\Assets\Registry;
use Nette\Http\UrlImmutable;
use Raneomik\NettePWA\Manifest\Value\Icon;
use Raneomik\NettePWA\Manifest\Value\Issue;
use Raneomik\NettePWA\Manifest\Value\Manifest;

/**
 * Checks the manifest the way a browser would before offering to install the app, plus what the
 * browser cannot see: the icon files themselves. Development tool (Tracy panel), not run in production.
 */
final readonly class ManifestInspector
{
    private const int ShortNameLength = 12; // longer names get truncated under the home screen icon

    /**
     * @param ?string $wwwDir where "/..." icon URLs are looked for; null = not checked
     */
    public function __construct(
        private Manifest $manifest,
        private ?Registry $assets = null,
        private ?string $wwwDir = null,
    ) {
    }

    /**
     * @param UrlImmutable $manifestUrl relative manifest URLs (start_url, scope, icons) resolve against it
     * @return list<Issue>
     */
    public function inspect(UrlImmutable $manifestUrl): array
    {
        $manifest = $this->manifest;
        $issues = [];

        if (! $manifest->display->isInstallable()) {
            $issues[] = new Issue(Issue::Error, "display: '{$manifest->display->value}' is not installable, use standalone, fullscreen or minimal-ui.");
        }

        if (true === ($manifest->extra['prefer_related_applications'] ?? false)) {
            $issues[] = new Issue(Issue::Error, 'prefer_related_applications: true, browsers offer the native app instead.');
        }

        $start = $manifestUrl->resolve($manifest->startUrl);
        $scope = $manifestUrl->resolve($manifest->scope);
        if ($start->getHostUrl() !== $scope->getHostUrl() || ! str_starts_with($start->getPath(), $scope->getPath())) {
            $issues[] = new Issue(Issue::Error, "start_url ({$start->getPath()}) is out of scope ({$scope->getPath()}): browsers ignore it.");
        }

        $issues = [...$issues, ...$this->inspectIcons($manifest->icons, $manifestUrl)];

        if (null === $manifest->shortName && self::ShortNameLength < mb_strlen($manifest->name)) {
            $issues[] = new Issue(Issue::Warning, "No shortName: '{$manifest->name}' gets truncated under the home screen icon.");
        }

        if (null === $manifest->themeColor) {
            $issues[] = new Issue(Issue::Tip, 'No themeColor: the title bar of the installed app keeps the default color.');
        }

        if (null === $manifest->backgroundColor) {
            $issues[] = new Issue(Issue::Tip, 'No backgroundColor: the splash screen is white.');
        }

        if (null === $manifest->id) {
            $issues[] = new Issue(Issue::Tip, 'No id: changing startUrl later makes the installed app a different app.');
        }

        return $issues;
    }

    /**
     * @param list<Icon> $icons
     * @return list<Issue>
     */
    private function inspectIcons(array $icons, UrlImmutable $manifestUrl): array
    {
        $issues = [];
        $largest = 0;
        $maskable = false;

        foreach ($icons as $icon) {
            $declared = $this->declaredSizes($icon->sizes);
            $file = $this->file($icon, $manifestUrl, $issues);
            $actual = null === $file ? null : @getimagesize($file); // an SVG: false, or its width/height since PHP 8.5

            if (\is_array($actual)) {
                [$width, $height] = $actual;
                $scalable = \in_array([\PHP_INT_MAX, \PHP_INT_MAX], $declared, true); // "any": no file size contradicts it
                if ([] !== $declared && ! $scalable && ! \in_array([$width, $height], $declared, true)) {
                    $issues[] = new Issue(Issue::Warning, "{$icon->src}: declared {$icon->sizes}, the file is {$width}x{$height}: browsers may discard it.");
                }

                $declared = [] === $declared ? [[$width, $height]] : $declared;
            }

            foreach ($declared as [$width, $height]) {
                $largest = max($largest, min($width, $height));
            }

            if (str_contains($icon->purpose ?? '', 'maskable')) {
                $maskable = true;
            }
        }

        if (144 > $largest) {
            $issues[] = new Issue(Issue::Error, [] === $icons
                ? 'No icon: an icon of at least 144x144 (or an SVG with sizes "any") is needed to install.'
                : 'No icon of at least 144x144 (or an SVG with sizes "any"): needed to install.');
        } elseif (512 > $largest) { // a larger icon is scaled down: one 512 (or an SVG) covers both
            $issues[] = new Issue(Issue::Warning, "Largest icon {$largest}x{$largest}: Android wants 192x192 for the home screen and 512x512 for the splash screen.");
        }

        if ([] !== $icons && ! $maskable) {
            $issues[] = new Issue(Issue::Tip, 'No icon with purpose "maskable": Android shows the icon shrunk on a white disc.');
        }

        return $issues;
    }

    /**
     * @return list<array{int, int}> "any" = PHP_INT_MAX
     */
    private function declaredSizes(?string $sizes): array
    {
        $declared = [];
        foreach (preg_split('/\s+/', strtolower((string) $sizes)) ?: [] as $size) {
            if ('any' === $size) {
                $declared[] = [\PHP_INT_MAX, \PHP_INT_MAX];
            } elseif (preg_match('/^(?<width>\d+)x(?<height>\d+)$/', $size, $match)) {
                $declared[] = [(int) $match['width'], (int) $match['height']];
            }
        }

        return $declared;
    }

    /**
     * @param list<Issue> $issues
     * @return ?string the local file, null when it cannot be checked from here
     */
    private function file(Icon $icon, UrlImmutable $manifestUrl, array &$issues): ?string
    {
        $src = $icon->src;

        // without Nette Assets, ManifestProvider publishes a reference as is: checked as a URL below
        if ($icon->isAssetReference() && $this->assets instanceof Registry) {
            try {
                $asset = $this->assets->getAsset($src);
            } catch (\Throwable $throwable) {
                $issues[] = new Issue(Issue::Error, "{$src}: {$throwable->getMessage()}");

                return null;
            }

            return $asset instanceof ImageAsset ? $asset->file : null;
        }

        $url = $manifestUrl->resolve($src);
        if (null === $this->wwwDir || $url->getHostUrl() !== $manifestUrl->getHostUrl()) {
            return null; // another host (CDN): not checked
        }

        $basePath = rtrim(\dirname($manifestUrl->getPath()), '/') . '/';
        if (! str_starts_with($url->getPath(), $basePath)) {
            $issues[] = new Issue(Issue::Error, "{$src}: {$url->getPath()} is outside the app ({$basePath}), not served from its www dir.");

            return null;
        }

        $file = $this->wwwDir . '/' . substr($url->getPath(), \strlen($basePath));
        if (is_file($file)) {
            return $file;
        }

        $issues[] = new Issue(Issue::Error, "{$src}: not found ({$file}).");

        return null;
    }
}
