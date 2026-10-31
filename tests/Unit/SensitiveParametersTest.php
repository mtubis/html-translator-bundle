<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Unit;

use HtmlTranslator\Bundle\ChatModel\ChatModelFactory;
use HtmlTranslator\Bundle\DependencyInjection\ConfigurationTree;
use HtmlTranslator\Bundle\DependencyInjection\ProviderDefinitions;
use HtmlTranslator\Bundle\DependencyInjection\TranslatorDefinitions;
use HtmlTranslator\Bundle\HtmlTranslatorBundle;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SensitiveParameter;

/**
 * Parameters receiving provider entries or the whole configuration hold API keys and header values, which must not
 * show up in stack traces (CLAUDE.md). Guards methods added later, too.
 */
#[CoversNothing]
final class SensitiveParametersTest extends TestCase
{
    /** Parameter names that receive entries or configuration. */
    private const array NAMES = ['entry', 'config'];

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function classes(): iterable
    {
        foreach ([ChatModelFactory::class, ProviderDefinitions::class, TranslatorDefinitions::class, ConfigurationTree::class, HtmlTranslatorBundle::class] as $class) {
            yield $class => [$class];
        }
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('classes')]
    public function it_marks_every_entry_parameter_as_sensitive(string $class): void
    {
        $unmarked = [];
        foreach (new ReflectionClass($class)->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            foreach ($method->getParameters() as $parameter) {
                if (\in_array($parameter->getName(), self::NAMES, true) && [] === $parameter->getAttributes(SensitiveParameter::class)) {
                    $unmarked[] = $method->getName() . '($' . $parameter->getName() . ')';
                }
            }
        }

        self::assertSame([], $unmarked);
    }
}
