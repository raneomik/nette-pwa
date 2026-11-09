<?php

declare(strict_types=1);

namespace Raneomik\NettePWA\Action;

use Nette\Application\Response;
use Nette\Http\IRequest;
use Nette\Http\IResponse;

/**
 * For files served at a fixed URL (manifest, service worker): the browser may keep them,
 * but must ask again each time (no-cache + ETag, 304 while unchanged), so a deploy is seen at once.
 */
final readonly class RevalidatedResponse implements Response
{
    public function __construct(
        private string $content,
        private string $contentType,
    ) {
    }

    public function send(IRequest $httpRequest, IResponse $httpResponse): void
    {
        $etag = '"' . hash('xxh128', $this->content) . '"';
        $httpResponse->setContentType($this->contentType, 'utf-8');
        $httpResponse->setHeader('Cache-Control', 'no-cache');
        $httpResponse->setHeader('ETag', $etag);

        if ($httpRequest->getHeader('If-None-Match') === $etag) {
            $httpResponse->setCode(IResponse::S304_NotModified);

            return;
        }

        echo $this->content;
    }
}
