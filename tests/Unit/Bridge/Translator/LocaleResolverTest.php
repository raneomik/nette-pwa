<?php

declare(strict_types=1);

namespace Tests\Raneomik\NettePWA\Unit\Bridge\Translator;

require \dirname(__DIR__, 3) . '/bootstrap.php';

use Contributte\Translation\FallbackResolver;
use Contributte\Translation\LocaleResolver as ContributteLocaleResolver;
use Contributte\Translation\Translator;
use Nette\DI\Container;
use Raneomik\NettePWA\Bridge\Translator\LocaleResolver;
use Tester\Assert;
use Tester\TestCase;

/**
 * @testCase
 */
final class LocaleResolverTest extends TestCase
{
    public function testEnglishOnlyWithoutATranslator(): void
    {
        Assert::same('en', (new LocaleResolver())->getLocale());
        Assert::same('en', (new LocaleResolver())->getLocale('fr'));
    }

    public function testTheRequestedLocaleIsKeptOnlyWhenTheTranslatorKnowsIt(): void
    {
        $translator = new Translator(new ContributteLocaleResolver(new Container()), new FallbackResolver(), 'cs');
        $translator->setLocalesWhitelist(['cs', 'fr', 'pt-BR']);

        $resolver = new LocaleResolver($translator);

        Assert::same('fr', $resolver->getLocale('fr'));
        Assert::same('fr', $resolver->getLocale('fr-FR'), 'the language of a regional <html lang>');
        Assert::same('fr', $resolver->getLocale('FR'));
        Assert::same('pt-BR', $resolver->getLocale('pt-BR'), 'as is first');
        Assert::same('cs', $resolver->getLocale('de'), 'unknown: what the translator resolves, here its default');
        Assert::same('cs', $resolver->getLocale());
    }
}

(new LocaleResolverTest())->run();
