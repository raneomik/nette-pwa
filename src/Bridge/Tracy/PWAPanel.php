<?php

declare(strict_types=1);

namespace Raneomik\NettePWA\Bridge\Tracy;

use Nette\Http\IRequest;
use Nette\Http\UrlImmutable;
use Nette\Utils\FileSystem;
use Raneomik\NettePWA\Manifest\ManifestInspector;
use Raneomik\NettePWA\Manifest\Value\Issue;
use Raneomik\NettePWA\Push\Debug\SendLogger;
use Raneomik\NettePWA\Push\Debug\SentPush;
use Tracy\Helpers;
use Tracy\IBarPanel;

/**
 * PWA panel (community logo, CC0): what the browser DevTools cannot show. The manifest checked against the install criteria
 * and the icon files, the service worker actually served, and the pushes sent since the last panel.
 * Registered by PWAExtension in debug mode only.
 */
final class PWAPanel implements IBarPanel
{
    private const array SecureHosts = ['localhost', '127.0.0.1', '[::1]', '::1'];

    /**
     * @var ?array{manifest: list<Issue>, serviceWorker: list<Issue>, push: list<Issue>, sent: list<SentPush>}
     */
    private ?array $data = null;

    /**
     * @param ?array{publicKey: string, subject: string, pushServices: list<string>, subscribePath: string} $push null = push not configured
     */
    public function __construct(
        private readonly ManifestInspector $inspector,
        private readonly IRequest $request,
        private readonly string $serviceWorker,
        private readonly bool $customServiceWorker,
        private readonly ?array $push = null,
        private readonly ?SendLogger $logger = null,
    ) {
    }

    public function getTab(): string
    {
        $data = $this->data();
        $errors = \count(array_filter(
            [
                ...$data['manifest'],
                ...$data['serviceWorker'],
                ...$data['push'],
            ],
            static fn (Issue $issue): bool => Issue::Error === $issue->severity,
        ));
        $failed = \count(array_filter($data['sent'], static fn (SentPush $sent): bool => [] !== $sent->failures));

        return Helpers::capture(function () use ($errors, $failed, $data): void {
            $sent = \count($data['sent']);
            // px like the Tracy bar: rem follows the host page's root font size
            $logo = $this->svg('tab-logo.svg', 'width:22.1px;height:13px;vertical-align:middle');

            require __DIR__ . '/dist/tab.phtml';
        });
    }

    public function getPanel(): string
    {
        $data = $this->data();

        return Helpers::capture(function () use ($data): void {
            $baseUrl = $this->request->getUrl()->getBaseUrl();
            $serviceWorker = $this->serviceWorker;
            $customServiceWorker = $this->customServiceWorker;
            $push = $this->push;
            $logo = $this->svg('logo.svg', 'height:1em;width:2.66em;vertical-align:middle'); // viewBox ratio 1952/735

            require __DIR__ . '/dist/panel.phtml';
        });
    }

    /**
     * sized inline: Tracy squares every bar SVG into an icon (#tracy-debug-bar svg {width: 1.23em})
     *
     * @param 'logo.svg'|'tab-logo.svg' $file
     */
    private function svg(string $file, string $style): string
    {
        static $svgs = [];
        $svgs[$file] ??= trim((string) preg_replace('/<!--.*?-->\s*/s', '', (string) file_get_contents(__DIR__ . '/dist/' . $file)));

        // mask id unique per copy, across requests too (an AJAX bar joins the page): with duplicates the first wins,
        // and Chrome renders no mask defined inside a hidden (display: none) panel
        return str_replace(['{STYLE}', '{ID}'], [$style, 'pwa-logo-' . bin2hex(random_bytes(4))], $svgs[$file]);
    }

    /**
     * @return array{manifest: list<Issue>, serviceWorker: list<Issue>, push: list<Issue>, sent: list<SentPush>}
     */
    private function data(): array
    {
        // Tracy renders the tab and the panel at the end of the request: the log is complete by then
        return $this->data ??= [
            'manifest' => $this->inspector->inspect(new UrlImmutable($this->request->getUrl()->getBaseUrl() . 'manifest.webmanifest')),
            'serviceWorker' => $this->inspectServiceWorker(),
            'push' => $this->inspectPush(),
            'sent' => $this->logger?->drain() ?? [],
        ];
    }

    /**
     * @return list<Issue>
     */
    private function inspectServiceWorker(): array
    {
        $issues = [];
        $url = $this->request->getUrl();
        if ('https' !== $url->getScheme() && ! \in_array($url->getHost(), self::SecureHosts, true)) {
            $issues[] = new Issue(
                Issue::Error,
                sprintf(
                    'Not a secure context (%s://%s): no service worker, no install, no push. Use HTTPS, or localhost.',
                    $url->getScheme(),
                    $url->getHost(),
                )
            );
        }

        if (! is_file($this->serviceWorker)) {
            $issues[] = new Issue(
                Issue::Error,
                sprintf('%s: not found, /sw.js fails.', $this->serviceWorker)
            );

            return $issues;
        }

        if (null !== $this->push && $this->customServiceWorker) {
            $code = FileSystem::read($this->serviceWorker);
            foreach ([
                'push' => 'push notifications are never shown',
                'notificationclick' => 'a click on a notification opens nothing',
                'pushsubscriptionchange' => 'a rotated subscription is lost',
            ] as $event => $consequence) {
                if (! preg_match('/[\'"]' . $event . '[\'"]/', $code)) {
                    $issues[] = new Issue(
                        'push' === $event ? Issue::Error : Issue::Warning,
                        sprintf('No "%s" listener: %s. Start from the package\'s assets/sw.js.', $event, $consequence),
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * @return list<Issue>
     */
    private function inspectPush(): array
    {
        if (null === $this->push) {
            return [];
        }

        if ('' === $this->push['publicKey']) {
            return [new Issue(Issue::Warning, 'Empty VAPID keys: push is off, {pwaHead()} hides the opt-in.')];
        }

        $host = parse_url($this->push['subject'], \PHP_URL_HOST);
        if (\is_string($host) && \in_array($host, self::SecureHosts, true)) {
            return [
                new Issue(
                    Issue::Warning,
                    sprintf(
                        'VAPID subject "%s": Apple refuses localhost, Safari subscribers get nothing.',
                        $this->push['subject'],
                    )
                ),
            ];
        }

        return [];
    }
}
