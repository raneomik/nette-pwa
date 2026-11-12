<?php

declare(strict_types=1);

namespace Raneomik\NettePWA\Bridge\Translator;

use Contributte\Translation\Translator;

final readonly class LocaleResolver
{
    public const string DefaultLocale = 'en';

    public function __construct(
        private ?Translator $translator = null,
    ) {
    }

    /**
     * @param ?string $requested untrusted (push-client.js sends <html lang>): kept only when the translator knows it.
     *                           The subscribe route has no locale parameter: the translator alone would often answer its default
     */
    public function getLocale(?string $requested = null): string
    {
        if (! $this->translator instanceof Translator) {
            return self::DefaultLocale;
        }

        $known = $this->translator->getLocalesWhitelist() ?? $this->translator->getAvailableLocales();

        // <html lang="fr-FR">: as is, as "fr_FR", then as "fr"
        $candidates = null === $requested
            ? []
            : [
                $requested,
                strtr($requested, '-', '_'),
                strtolower((string) strtok($requested, '-_')),
            ];
        foreach ($candidates as $candidate) {
            if (\in_array($candidate, $known, true)) {
                return $candidate;
            }
        }

        return $this->translator->getLocale();
    }
}
