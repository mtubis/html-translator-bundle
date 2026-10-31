<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Exception;

use HtmlTranslator\Exception\TranslatorException;
use InvalidArgumentException;
use Throwable;

/**
 * A configuration problem found at runtime, i.e. in a value that may come from env (ADR-0004). The message names the
 * config path and never quotes the value, which may be a secret.
 */
final class InvalidConfigurationException extends InvalidArgumentException implements TranslatorException
{
    /**
     * @param string $path e.g. "html_translator.providers.openai.api_key"
     * @param string $problem e.g. "is empty; the reference configuration reads it from OPENAI_API_KEY"
     */
    public static function forPath(string $path, string $problem, ?Throwable $previous = null): self
    {
        return new self($path . ': ' . $problem, previous: $previous);
    }
}
