<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Functional\Fixtures;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Stands in for the "logger" service of an application without MonologBundle.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string}> */
    public array $records = [];

    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message];
    }
}
