<?php

declare(strict_types=1);

namespace Tests\Raneomik\NettePWA\Unit\Manifest;

require \dirname(__DIR__, 2) . '/bootstrap.php';

use Nette\Assets\FilesystemMapper;
use Nette\Assets\Registry;
use Raneomik\NettePWA\Manifest\ManifestProvider;
use Tester\Assert;
use Tester\TestCase;

/**
 * @testCase
 */
final class ManifestProviderTest extends TestCase
{
    public function testMembersMappedNullsLeftOutExtraMerged(): void
    {
        $provider = new ManifestProvider([
            'name' => 'Demo',
            'shortName' => 'D',
            'description' => null,
            'startUrl' => '/',
            'backgroundColor' => '#000',
            'themeColor' => '#111',
            'icons' => [[
                'src' => '/icon.png',
                'sizes' => '192x192',
                'type' => null,
                'purpose' => 'maskable',
            ]],
            'extra' => [
                'categories' => ['games'],
                'name' => 'ignored: members win',
            ],
        ]);

        Assert::same([
            'name' => 'Demo',
            'short_name' => 'D',
            'start_url' => '/',
            'background_color' => '#000',
            'theme_color' => '#111',
            'icons' => [[
                'src' => '/icon.png',
                'sizes' => '192x192',
                'purpose' => 'maskable',
            ]],
            'categories' => ['games'],
        ], $provider->build());
        Assert::same('#111', $provider->getThemeColor());
    }

    public function testNoIconsNoThemeColor(): void
    {
        $provider = new ManifestProvider([
            'name' => 'Demo',
        ]);

        Assert::same([
            'name' => 'Demo',
            'icons' => [],
        ], $provider->build());
        Assert::null($provider->getThemeColor());
    }

    public function testIconSourcesUrlOrAssetReference(): void
    {
        $assets = new Registry();
        $assets->addMapper('img', new FilesystemMapper('https://cdn.example.com/img', \dirname(__DIR__, 2) . '/Fixtures/img', ['svg'], versioning: false));

        $provider = new ManifestProvider([
            'name' => 'Demo',
            'icons' => [
                [
                    'src' => 'img:icon',
                ],
                [
                    'src' => '/static/icon.svg',
                ],
                [
                    'src' => 'https://other.example.com/icon.svg',
                ],
            ],
        ], $assets);

        Assert::same(
            ['https://cdn.example.com/img/icon.svg', '/static/icon.svg', 'https://other.example.com/icon.svg'],
            array_column($provider->build()['icons'], 'src'),
        );
    }

    public function testAssetReferenceWithoutNetteAssetsIsKeptAsIs(): void
    {
        Assert::same('img:icon', (new ManifestProvider([
            'name' => 'Demo',
            'icons' => [[
                'src' => 'img:icon',
            ]],
        ]))->build()['icons'][0]['src']);
    }

    public function testJsonIsPrettyAndBuiltOnce(): void
    {
        $provider = new ManifestProvider([
            'name' => 'Démo / app',
        ]);

        Assert::same("{\n    \"name\": \"Démo / app\",\n    \"icons\": []\n}", $provider->getJson());
        Assert::same($provider->getJson(), $provider->getJson());
    }
}

(new ManifestProviderTest())->run();
