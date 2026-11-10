<?php

declare(strict_types=1);

namespace Raneomik\NettePWA\Manifest\Value;

final readonly class Issue
{
    public const string Error = 'error'; // the app cannot be installed (Chrome's install criteria)

    public const string Warning = 'warning'; // installable, but degraded somewhere

    public const string Tip = 'tip'; // recommended

    /**
     * @param self::Error|self::Warning|self::Tip $severity
     */
    public function __construct(
        public string $severity,
        public string $message,
    ) {
    }
}
