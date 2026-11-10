<?php

declare(strict_types=1);

namespace Tests\Raneomik\NettePWA\Unit\Action;

require \dirname(__DIR__, 2) . '/bootstrap.php';

use Nette\Http\Request;
use Nette\Http\UrlScript;
use Nette\Utils\FileSystem;
use Raneomik\NettePWA\Action\ServiceWorkerAction;
use Tester\Assert;
use Tester\TestCase;
use Tests\Raneomik\NettePWA\Fixtures\RecordingResponse;

/**
 * @testCase
 */
final class ServiceWorkerActionTest extends TestCase
{
    public function testTheFileIsReadOncePerWorker(): void
    {
        $file = __DIR__ . '/../../output/sw-action-' . getmypid() . '.js';
        FileSystem::write($file, 'self.skipWaiting();');
        $action = new ServiceWorkerAction($file);

        Assert::same('self.skipWaiting();', $this->body($action));
        FileSystem::delete($file);
        Assert::same('self.skipWaiting();', $this->body($action), 'kept in memory: no disk read per request');
    }

    private function body(ServiceWorkerAction $action): string
    {
        ob_start();
        $action()->send(new Request(new UrlScript('https://example.com/sw.js')), new RecordingResponse());

        return (string) ob_get_clean();
    }
}

(new ServiceWorkerActionTest())->run();
