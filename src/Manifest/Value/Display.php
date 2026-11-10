<?php

declare(strict_types=1);

namespace Raneomik\NettePWA\Manifest\Value;

/**
 * "window-controls-overlay" is only valid in display_override (an "extra" member), not here
 */
enum Display: string
{
    case Fullscreen = 'fullscreen';
    case Standalone = 'standalone';
    case MinimalUi = 'minimal-ui';
    case Browser = 'browser';

    public function isInstallable(): bool
    {
        return self::Browser !== $this;
    }
}
