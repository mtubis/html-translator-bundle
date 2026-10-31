<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Unit\Profile;

use HtmlTranslator\Bundle\Exception\InvalidConfigurationException;
use HtmlTranslator\Bundle\Profile\ProfileFactory;
use HtmlTranslator\Bundle\Profile\ProfileRegistry;
use HtmlTranslator\Profile\TranslationProfile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProfileRegistry::class)]
#[UsesClass(ProfileFactory::class)]
#[UsesClass(InvalidConfigurationException::class)]
final class ProfileRegistryTest extends TestCase
{
    #[Test]
    public function it_builds_each_profile_once(): void
    {
        $registry = $this->registry();

        self::assertSame($registry->get('docs'), $registry->get('docs'));
        self::assertSame(TranslationProfile::technical()->fingerprint(), $registry->get('docs')->fingerprint());
    }

    #[Test]
    public function it_returns_the_default_profile_without_a_name(): void
    {
        $registry = $this->registry();

        self::assertSame($registry->get('default'), $registry->get());
        self::assertSame('default', $registry->defaultName());
    }

    #[Test]
    public function it_lists_the_profile_names(): void
    {
        $registry = $this->registry();

        self::assertSame(['default', 'docs'], $registry->names());
        self::assertTrue($registry->has('docs'));
        self::assertFalse($registry->has('legal'));
    }

    #[Test]
    public function it_names_the_defined_profiles_for_an_unknown_name(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('html_translator.profiles.legal: is not defined; defined: default, docs');

        $this->registry()->get('legal');
    }

    private function registry(): ProfileRegistry
    {
        return new ProfileRegistry(['default' => ['preset' => 'default'], 'docs' => ['preset' => 'technical']], 'default');
    }
}
