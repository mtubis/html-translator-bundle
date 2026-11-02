<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Unit;

use HtmlTranslator\Bundle\Drivers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Drivers::class)]
final class DriversTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function drivers(): iterable
    {
        foreach (Drivers::ALL as $driver) {
            yield $driver => [$driver];
        }
    }

    #[Test]
    #[DataProvider('drivers')]
    public function it_lists_the_required_keys_among_the_allowed_keys(string $driver): void
    {
        self::assertSame([], array_diff(Drivers::requiredKeysOf($driver), Drivers::keysOf($driver)));
    }

    #[Test]
    public function it_uses_the_key_names_of_the_laravel_package(): void
    {
        self::assertSame(['api_key', 'model', 'json_mode'], Drivers::keysOf('openai'));
        self::assertSame(['api_key', 'model', 'json_mode'], Drivers::keysOf('gemini'));
        self::assertSame(['model', 'base_url', 'json_mode'], Drivers::keysOf('ollama'));
        self::assertSame(['model', 'base_url', 'api_key', 'json_mode'], Drivers::keysOf('vllm'));
        self::assertSame(['model', 'base_url', 'json_mode'], Drivers::keysOf('lm_studio'));
        self::assertSame(['name', 'base_url', 'api_key', 'model', 'json_mode', 'max_tokens_field', 'extra_headers'], Drivers::keysOf('openai_compatible'));
        self::assertSame(['api_key', 'model', 'effort', 'base_url', 'refusal_fallbacks', 'betas', 'extra_headers', 'api_version', 'default_max_output_tokens'], Drivers::keysOf('anthropic'));
        self::assertSame(['corrupt_every', 'corrupt_retries'], Drivers::keysOf('pseudo'));
    }

    #[Test]
    public function it_requires_what_the_core_configs_cannot_default(): void
    {
        self::assertSame(['api_key', 'model'], Drivers::requiredKeysOf('openai'));
        self::assertSame(['api_key', 'model'], Drivers::requiredKeysOf('gemini'));
        self::assertSame(['api_key', 'model'], Drivers::requiredKeysOf('anthropic'));
        self::assertSame(['model'], Drivers::requiredKeysOf('ollama'));
        self::assertSame(['model'], Drivers::requiredKeysOf('vllm'));
        self::assertSame(['model'], Drivers::requiredKeysOf('lm_studio'));
        self::assertSame(['base_url', 'model'], Drivers::requiredKeysOf('openai_compatible'));
        self::assertSame([], Drivers::requiredKeysOf('pseudo'));
    }

    #[Test]
    public function it_knows_every_key_any_driver_accepts(): void
    {
        self::assertSame(
            ['api_key', 'model', 'json_mode', 'base_url', 'name', 'max_tokens_field', 'extra_headers', 'effort', 'refusal_fallbacks', 'betas', 'api_version', 'default_max_output_tokens', 'corrupt_every', 'corrupt_retries'],
            Drivers::allKeys(),
        );
    }

    #[Test]
    public function it_keeps_reserved_drivers_out_of_the_available_ones(): void
    {
        // What a reserved driver reports is tested with the configuration tree.
        self::assertSame([], array_intersect(array_keys(Drivers::RESERVED), Drivers::ALL));
    }
}
