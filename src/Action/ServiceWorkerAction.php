<?php

declare(strict_types=1);

namespace Raneomik\NettePWA\Action;

use Nette\Application\Response;
use Nette\Utils\FileSystem;

/**
 * GET /sw.js (route callback): served from the package, at the root for a "/" scope.
 * The "serviceWorker" option points to the app's own file to extend it (e.g. offline cache).
 */
final class ServiceWorkerAction
{
    private ?string $content = null;

    public function __construct(
        private readonly string $file,
    ) {
    }

    public function __invoke(): Response
    {
        return new RevalidatedResponse($this->content ??= FileSystem::read($this->file), 'text/javascript');
    }
}
