<?php

declare(strict_types=1);

namespace Tests\Raneomik\NettePWA\Fixtures;

use Nette\Bootstrap\Configurator;
use Nette\DI\Container;
use Raneomik\NettePWA\Bridge\DI\PWAExtension;
use Tester\FileMock;
use Tester\Helpers;

final class ContainerFactory
{
    /**
     * the NEON parts are merged, as several config files would be
     */
    public static function create(string ...$neon): Container
    {
        // one dir per process, removed at exit: a shared one would serve containers compiled from an
        // older source (or an Infection mutant), Nette does not re-check the sources outside debug mode
        $processDir = __DIR__ . '/../output/containers/' . getmypid();
        if (! is_dir($processDir)) {
            mkdir($processDir, recursive: true);
            register_shutdown_function(static function () use ($processDir): void {
                Helpers::purge($processDir);
                rmdir($processDir);
            });
        }

        $tempDir = $processDir . '/' . md5(implode("\n", $neon));

        $configurator = (new Configurator())
            ->setTempDirectory($tempDir)
            ->addConfig(FileMock::create("database:\n    dsn: 'sqlite::memory:'", 'neon'));
        foreach ($neon as $part) {
            $configurator->addConfig(FileMock::create($part, 'neon'));
        }

        $configurator->onCompile[] = static fn ($_, $compiler) => $compiler->addExtension('pwa', new PWAExtension());

        return $configurator->createContainer();
    }
}
