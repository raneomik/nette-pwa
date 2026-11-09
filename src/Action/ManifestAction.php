<?php

declare(strict_types=1);

namespace Raneomik\NettePWA\Action;

use Nette\Application\Response;
use Raneomik\NettePWA\Manifest\ManifestProvider;

/**
 * GET /manifest.webmanifest (route callback, Nette:Micro presenter)
 */
final readonly class ManifestAction
{
    public function __construct(
        private ManifestProvider $manifest,
    ) {
    }

    public function __invoke(): Response
    {
        return new RevalidatedResponse($this->manifest->getJson(), 'application/manifest+json');
    }
}
