<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Functional\Fixtures;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Canned vendor responses (vendor/README.md), for tests and as service factories in scenario fixtures.
 */
final class VendorResponses
{
    private const string DIR = __DIR__ . '/vendor/';

    public static function openAiCompatibleSuccess(): MockResponse
    {
        return self::json('openai-compatible-success.json', 200);
    }

    public static function anthropicSuccess(): MockResponse
    {
        return self::json('anthropic-success.json', 200);
    }

    public static function rateLimited(): MockResponse
    {
        return self::json('openai-compatible-error-429.json', 429);
    }

    private static function json(string $file, int $status): MockResponse
    {
        return new MockResponse((string) file_get_contents(self::DIR . $file), [
            'http_code' => $status,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }
}
