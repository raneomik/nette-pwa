<?php

declare(strict_types=1);

namespace Tests\Raneomik\NettePWA\Unit\Manifest;

require \dirname(__DIR__, 2) . '/bootstrap.php';

use Nette\Assets\FilesystemMapper;
use Nette\Assets\Registry;
use Nette\Http\UrlImmutable;
use Nette\Utils\FileSystem;
use Raneomik\NettePWA\Manifest\ManifestInspector;
use Raneomik\NettePWA\Manifest\Value\Display;
use Raneomik\NettePWA\Manifest\Value\Icon;
use Raneomik\NettePWA\Manifest\Value\Issue;
use Raneomik\NettePWA\Manifest\Value\Manifest;
use Tester\Assert;
use Tester\TestCase;

/**
 * @testCase
 */
final class ManifestInspectorTest extends TestCase
{
    private string $www;

    protected function setUp(): void
    {
        $this->www = __DIR__ . '/../../output/www-' . getmypid();
        FileSystem::createDir($this->www . '/icons');
        $this->png('icons/192.png', 192);
        $this->png('icons/512.png', 512);
        $this->png('icons/small.png', 96);
        $this->png('icons/144.png', 144);
        FileSystem::write($this->www . '/icons/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        FileSystem::write($this->www . '/icons/sized.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512"/>');
    }

    protected function tearDown(): void
    {
        FileSystem::delete($this->www);
    }

    public function testACompleteManifestHasNoIssue(): void
    {
        Assert::same([], $this->inspect([
            'shortName' => 'Demo',
            'id' => '/',
            'themeColor' => '#123',
            'backgroundColor' => '#fff',
            'icons' => [
                $this->icon('/icons/192.png', '192x192'),
                $this->icon('/icons/512.png', '512x512', 'any maskable'),
            ],
        ]));
    }

    public function testInstallCriteria(): void
    {
        $issues = $this->inspect([
            'display' => Display::Browser,
            'startUrl' => '/admin/',
            'scope' => '/app/',
            'extra' => [
                'prefer_related_applications' => true,
            ],
        ]);

        Assert::same([
            "display: 'browser' is not installable, use standalone, fullscreen or minimal-ui.",
            'prefer_related_applications: true, browsers offer the native app instead.',
            'start_url (/admin/) is out of scope (/app/): browsers ignore it.',
            'No icon: an icon of at least 144x144 (or an SVG with sizes "any") is needed to install.',
        ], $this->messages($issues, Issue::Error));
    }

    public function testRelativeUrlsResolveAgainstTheManifest(): void
    {
        // app in a subdirectory: "." = /app/, and icons/logo.svg = /app/icons/logo.svg = {wwwDir}/icons/logo.svg
        $issues = $this->inspect([
            'startUrl' => '.',
            'scope' => '.',
            'icons' => [$this->icon('icons/logo.svg', 'any')],
        ], 'https://example.com/app/manifest.webmanifest');

        Assert::same([], $this->messages($issues, Issue::Error));

        // "/icons/..." is outside the app there: not one of its files
        $issues = $this->inspect([
            'icons' => [$this->icon('/icons/logo.svg', 'any')],
        ], 'https://example.com/app/manifest.webmanifest');
        Assert::same(
            ['/icons/logo.svg: /icons/logo.svg is outside the app (/app/), not served from its www dir.'],
            $this->messages($issues, Issue::Error),
        );
    }

    public function testIconFiles(): void
    {
        $issues = $this->inspect([
            'icons' => [
                $this->icon('/icons/missing.png', '512x512'),
                $this->icon('/icons/192.png', '512x512'), // declared bigger than it is
                $this->icon('/icons/small.png'),           // no sizes: the file tells
                $this->icon('https://cdn.example.net/icon.png', '1024x1024'), // not checked
            ],
        ]);

        Assert::same([
            "/icons/missing.png: not found ({$this->www}/icons/missing.png).",
        ], $this->messages($issues, Issue::Error));
        Assert::contains(
            '/icons/192.png: declared 512x512, the file is 192x192: browsers may discard it.',
            $this->messages($issues, Issue::Warning),
        );
    }

    public function testSmallIconsOnly(): void
    {
        $issues = $this->inspect([
            'icons' => [$this->icon('/icons/small.png', '96x96')],
        ]);
        Assert::same(['No icon of at least 144x144 (or an SVG with sizes "any"): needed to install.'], $this->messages($issues, Issue::Error));

        $issues = $this->inspect([
            'icons' => [$this->icon('/icons/192.png', '192x192')],
        ]);
        Assert::same([], $this->messages($issues, Issue::Error));
        Assert::same(
            ['Largest icon 192x192: Android wants 192x192 for the home screen and 512x512 for the splash screen.'],
            $this->messages($issues, Issue::Warning),
        );
    }

    public function testWithoutSizesTheFileTells(): void
    {
        Assert::same([], $this->messages($this->inspect([
            'icons' => [$this->icon('/icons/512.png', purpose: 'maskable')],
        ]), Issue::Error));
    }

    public function testTheInstallMinimumIs144(): void
    {
        $issues = $this->inspect([
            'icons' => [$this->icon('/icons/144.png', '144x144')],
        ]);

        Assert::same([], $this->messages($issues, Issue::Error));
        Assert::contains('Largest icon 144x144: Android wants 192x192 for the home screen and 512x512 for the splash screen.', $this->messages($issues, Issue::Warning));
    }

    public function testDeclaredSizes(): void
    {
        $sizesOf = fn (string $sizes): array => $this->messages($this->inspect([
            'icons' => [$this->icon('https://cdn.example.net/icon.png', $sizes, 'maskable')], // not checked: only the sizes count
        ]), Issue::Error);

        Assert::same([], $sizesOf('96x96 512x512'), 'the largest of several');
        Assert::same([], $sizesOf('512X512'), 'case-insensitive');
        Assert::same([], $sizesOf('ANY'));
        Assert::count(1, $sizesOf('x512x512 512x512px'), 'not a size');

        // width x height, compared with the file
        $issues = $this->messages($this->inspect([
            'icons' => [
                $this->icon('/icons/192.png', '512x192'),
                $this->icon('/icons/192.png', '192x512'),
            ],
        ]), Issue::Warning);
        Assert::contains('/icons/192.png: declared 512x192, the file is 192x192: browsers may discard it.', $issues);
        Assert::contains('/icons/192.png: declared 192x512, the file is 192x192: browsers may discard it.', $issues);
    }

    public function testTheShortNameLimitCountsCharacters(): void
    {
        $warnings = fn (string $name): array => $this->messages($this->inspect([
            'name' => $name,
            'icons' => [$this->icon('/icons/512.png', '512x512')],
        ]), Issue::Warning);

        Assert::same([], $warnings('Twelve chars'), '12: fits');
        Assert::same([], $warnings('Éléphant été'), '12 characters, 16 bytes');
        Assert::count(1, $warnings('Thirteen char'));
    }

    public function testAnSvgCoversEverySize(): void
    {
        $issues = $this->inspect([
            'icons' => [$this->icon('/icons/logo.svg', 'any', 'maskable')],
        ]);

        Assert::same([], $this->messages($issues, Issue::Error));
        Assert::same([], $this->messages($issues, Issue::Warning));
    }

    public function testAnSvgWithDimensionsStillCoversEverySize(): void
    {
        // PHP 8.5 getimagesize() reads them: "any" is not contradicted by 512x512
        $issues = $this->inspect([
            'icons' => [$this->icon('/icons/sized.svg', 'any', 'maskable')],
        ]);

        Assert::same([], $this->messages($issues, Issue::Warning));
    }

    public function testRecommendations(): void
    {
        $issues = $this->inspect([
            'name' => 'Rock Paper Scissors Lizard Spock',
            'icons' => [$this->icon('/icons/512.png', '512x512')],
        ]);

        Assert::same(
            ["No shortName: 'Rock Paper Scissors Lizard Spock' gets truncated under the home screen icon."],
            $this->messages($issues, Issue::Warning),
        );
        Assert::same([
            'No icon with purpose "maskable": Android shows the icon shrunk on a white disc.',
            'No themeColor: the title bar of the installed app keeps the default color.',
            'No backgroundColor: the splash screen is white.',
            'No id: changing startUrl later makes the installed app a different app.',
        ], $this->messages($issues, Issue::Tip));
    }

    public function testAssetReferencesAreCheckedThroughNetteAssets(): void
    {
        $assets = new Registry();
        $assets->addMapper('img', new FilesystemMapper('https://cdn.example.com/img', \dirname(__DIR__, 2) . '/Fixtures/img', ['svg'], versioning: false));

        $issues = (new ManifestInspector(new Manifest('Demo', icons: [
            $this->icon('img:icon', 'any', 'maskable'),
            $this->icon('img:missing'),
        ]), $assets, $this->www))->inspect(new UrlImmutable('https://example.com/manifest.webmanifest'));

        $errors = $this->messages($issues, Issue::Error);
        Assert::count(1, $errors); // img:icon found: its "any" size counts, no 144x144 error
        Assert::match('img:missing: %a%', $errors[0]);

        // the file behind the reference is checked
        $assets->addMapper('www', new FilesystemMapper('https://example.com', $this->www, ['png'], versioning: false));
        $issues = (new ManifestInspector(new Manifest('Demo', icons: [
            $this->icon('www:icons/192', '512x512'),
        ]), $assets))->inspect(new UrlImmutable('https://example.com/manifest.webmanifest'));
        Assert::contains('www:icons/192: declared 512x512, the file is 192x192: browsers may discard it.', $this->messages($issues, Issue::Warning));
    }

    public function testAnAssetReferenceWithoutAssetsIsNotChecked(): void
    {
        $issues = $this->inspect([
            'icons' => [$this->icon('img:icon', '512x512')],
        ]);

        Assert::same([], $this->messages($issues, Issue::Error));
    }

    /**
     * @param array<string, mixed> $config
     * @return list<Issue>
     */
    private function inspect(array $config, string $manifestUrl = 'https://example.com/manifest.webmanifest'): array
    {
        $manifest = new Manifest(...$config + [
            'name' => 'Demo',
        ]);

        return (new ManifestInspector($manifest, null, $this->www))->inspect(new UrlImmutable($manifestUrl));
    }

    private function icon(string $src, ?string $sizes = null, ?string $purpose = null): Icon
    {
        return new Icon($src, $sizes, purpose: $purpose);
    }

    /**
     * @param list<Issue> $issues
     * @return list<string>
     */
    private function messages(array $issues, string $severity): array
    {
        return array_values(array_map(
            static fn (Issue $issue): string => $issue->message,
            array_filter($issues, static fn (Issue $issue): bool => $severity === $issue->severity),
        ));
    }

    /**
     * the IHDR chunk is all getimagesize() reads: no GD needed
     */
    private function png(string $path, int $size): void
    {
        $ihdr = 'IHDR' . pack('NNCCCCC', $size, $size, 8, 6, 0, 0, 0);
        FileSystem::write(
            $this->www . '/' . $path,
            "\x89PNG\r\n\x1a\n" . pack('N', 13) . $ihdr . pack('N', crc32($ihdr)),
        );
    }
}

(new ManifestInspectorTest())->run();
