<?php

declare(strict_types=1);

namespace Tests\Raneomik\NettePWA\Unit\Action;

require \dirname(__DIR__, 2) . '/bootstrap.php';

use Nette\Http\Request;
use Nette\Http\Response;
use Nette\Http\UrlScript;
use Raneomik\NettePWA\Action\RevalidatedResponse;
use Tester\Assert;
use Tester\TestCase;
use Tests\Raneomik\NettePWA\Fixtures\RecordingResponse;

/**
 * One case per method: @testCase runs each in its own process, the HTTP code is process-wide.
 *
 * @testCase
 */
final class RevalidatedResponseTest extends TestCase
{
    private const string Content = '{"a":1}';

    public function testFirstRequestGetsTheContent(): void
    {
        Assert::same([self::Content, 200], $this->send(null));
    }

    public function testStaleEtagGetsTheContent(): void
    {
        Assert::same([self::Content, 200], $this->send('"stale"'));
    }

    public function testSameEtagGets304WithoutBody(): void
    {
        // computed here: getHeader() sees no sent header in CLI
        Assert::same(['', 304], $this->send('"' . hash('xxh128', self::Content) . '"'));
    }

    public function testHeadersMakeTheBrowserRevalidate(): void
    {
        $httpResponse = new RecordingResponse();
        ob_start();
        (new RevalidatedResponse(self::Content, 'application/manifest+json'))->send(new Request(new UrlScript('https://example.com/')), $httpResponse);
        ob_end_clean();

        Assert::same([
            'Content-Type' => 'application/manifest+json; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'ETag' => '"' . hash('xxh128', self::Content) . '"',
        ], $httpResponse->headers);
    }

    /**
     * @return array{string|false, int}
     */
    private function send(?string $ifNoneMatch): array
    {
        $httpResponse = new Response();
        $httpResponse->warnOnBuffer = false;
        ob_start();
        (new RevalidatedResponse(self::Content, 'application/manifest+json'))->send(
            new Request(new UrlScript('https://example.com/'), headers: array_filter([
                'If-None-Match' => $ifNoneMatch,
            ])),
            $httpResponse,
        );

        return [ob_get_clean(), $httpResponse->getCode()];
    }
}

(new RevalidatedResponseTest())->run();
