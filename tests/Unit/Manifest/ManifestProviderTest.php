<?php

declare(strict_types=1);

namespace Tests\Raneomik\NettePWA\Unit\Manifest;

require \dirname(__DIR__, 2) . '/bootstrap.php';

use Nette\Assets\FilesystemMapper;
use Nette\Assets\Registry;
use Nette\Utils\Json;
use Raneomik\NettePWA\Manifest\ManifestProvider;
use Raneomik\NettePWA\Manifest\Value\Display;
use Raneomik\NettePWA\Manifest\Value\Icon;
use Raneomik\NettePWA\Manifest\Value\Manifest;
use Tester\Assert;
use Tester\TestCase;

/**
 * @testCase
 */
final class ManifestProviderTest extends TestCase
{
    public function testMembersMappedNullsLeftOutExtraMerged(): void
    {
        $provider = new ManifestProvider(new Manifest(
            name: 'Demo',
            shortName: 'D',
            backgroundColor: '#000',
            themeColor: '#111',
            icons: [new Icon('/icon.png', '192x192', purpose: 'maskable')],
            extra: [
                'categories' => ['games'],
                'name' => 'ignored: members win',
            ],
        ));

        Assert::same([
            'name' => 'Demo',
            'short_name' => 'D',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#000',
            'theme_color' => '#111',
            'icons' => [[
                'src' => '/icon.png',
                'sizes' => '192x192',
                'purpose' => 'maskable',
            ]],
            'categories' => ['games'],
        ], $provider->build());
    }

    public function testIconSourcesUrlOrAssetReference(): void
    {
        $assets = new Registry();
        $assets->addMapper('img', new FilesystemMapper('https://cdn.example.com/img', \dirname(__DIR__, 2) . '/Fixtures/img', ['svg'], versioning: false));

        $provider = new ManifestProvider(new Manifest('Demo', icons: [
            new Icon('img:icon'),
            new Icon('/static/icon.svg'),
            new Icon('https://other.example.com/icon.svg'),
        ]), $assets);

        Assert::same(
            ['https://cdn.example.com/img/icon.svg', '/static/icon.svg', 'https://other.example.com/icon.svg'],
            array_column($provider->build()['icons'], 'src'),
        );
    }

    public function testAssetReferenceWithoutNetteAssetsIsKeptAsIs(): void
    {
        Assert::same(
            'img:icon',
            (new ManifestProvider(new Manifest('Demo', icons: [new Icon('img:icon')])))->build()['icons'][0]['src'],
        );
    }

    public function testTheValueObjectsEncodeAsTheSpec(): void
    {
        Assert::same(
            '{"name":"Demo","start_url":"/","scope":"/","display":"browser","icons":[{"src":"img:icon","sizes":"any"}]}',
            Json::encode(new Manifest('Demo', display: Display::Browser, icons: [new Icon('img:icon', 'any')])),
        );
    }

    public function testJsonIsPrettyAndBuiltOnce(): void
    {
        $provider = new ManifestProvider(new Manifest('Démo / app'));

        Assert::same(<<<'JSON'
            {
                "name": "Démo / app",
                "start_url": "/",
                "scope": "/",
                "display": "standalone",
                "icons": []
            }
            JSON
, $provider->getJson());
        Assert::same($provider->getJson(), $provider->getJson());
    }
}

(new ManifestProviderTest())->run();
