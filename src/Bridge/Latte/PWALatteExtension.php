<?php

declare(strict_types=1);

namespace Raneomik\NettePWA\Bridge\Latte;

use Latte\Extension;
use Latte\Runtime\Html;
use Nette\Http\IRequest;
use Nette\Utils\Html as El;
use Raneomik\NettePWA\Action\SubscribeAction;
use Raneomik\NettePWA\Manifest\Value\Manifest;

/**
 * {pwaHead()} in <head>: manifest link, theme color, and what assets/push-client.js reads
 */
final class PWALatteExtension extends Extension
{
    public function __construct(
        private readonly Manifest $manifest,
        private readonly IRequest $request,
        private readonly ?string $vapidPublicKey = null, // null = push disabled
        private readonly string $subscribePath = SubscribeAction::DefaultPath, // "subscribeAtPath", relative to the base path like the route
    ) {
    }

    public function getFunctions(): array
    {
        return [
            'pwaHead' => $this->head(...),
        ];
    }

    private function head(): Html
    {
        $base = rtrim($this->request->getUrl()->getBasePath(), '/');
        $tags = [
            El::el('link', [
                'rel' => 'manifest',
                'href' => "{$base}/manifest.webmanifest",
            ])];

        if (null !== ($color = $this->manifest->themeColor)) {
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
                'content' => "{$base}/" . ltrim($this->subscribePath, '/'),
            ]);
            $tags[] = El::el('meta', [
                'name' => 'pwa-sw-url',
                'content' => "{$base}/sw.js",
            ]);
        }

        return new Html(implode("\n", $tags));
    }
}
