<?php

declare(strict_types=1);

namespace Raneomik\NettePWA\Bridge\Latte;

use Latte\Extension;
use Latte\Runtime\Html;
use Nette\Http\IRequest;
use Nette\Utils\Html as El;
use Raneomik\NettePWA\Manifest\ManifestProvider;

/**
 * {pwaHead()} in <head>: manifest link, theme color, and what assets/push-client.js reads
 */
final class PWALatteExtension extends Extension
{
    public function __construct(
        private readonly ManifestProvider $manifest,
        private readonly IRequest $request,
        private readonly ?string $vapidPublicKey = null, // null = push disabled
    ) {
    }

    public function getFunctions(): array
    {
        return [
            'pwaHead' => $this->head(...),
        ];
    }

    public function head(): Html
    {
        $base = rtrim($this->request->getUrl()->getBasePath(), '/');
        $tags = [
            El::el('link', [
                'rel' => 'manifest',
                'href' => "{$base}/manifest.webmanifest",
            ])];

        if (null !== ($color = $this->manifest->getThemeColor())) {
            $tags[] = El::el('meta', [
                'name' => 'theme-color',
                'content' => $color,
            ]);
        }

        if (null !== $this->vapidPublicKey && '' !== $this->vapidPublicKey) {
            $tags[] = El::el('meta', [
                'name' => 'pwa-vapid-key',
                'content' => $this->vapidPublicKey,
            ]);
            $tags[] = El::el('meta', [
                'name' => 'pwa-subscribe-url',
                'content' => "{$base}/pwa/push/subscribe",
            ]);
            $tags[] = El::el('meta', [
                'name' => 'pwa-sw-url',
                'content' => "{$base}/sw.js",
            ]);
        }

        return new Html(implode("\n", array_map(strval(...), $tags)));
    }
}
