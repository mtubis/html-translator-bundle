<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests;

use Throwable;

/**
 * The text a log or an error page could show for an exception chain: messages plus the arguments of the trace frames in
 * the bundle and the core. Frames of tests and PHPUnit are left out: they hold the secrets as test data. Objects are
 * reduced to their class (a trace may hold the kernel and the container), so this stays small.
 */
final class TraceText
{
    private const int MAX_DEPTH = 4;

    public static function of(Throwable $exception): string
    {
        $parts = [];
        for ($e = $exception; $e instanceof Throwable; $e = $e->getPrevious()) {
            $parts[] = $e->getMessage();
            foreach ($e->getTrace() as $frame) {
                $class = $frame['class'] ?? '';
                if (str_starts_with($class, 'HtmlTranslator\\') && !str_starts_with($class, 'HtmlTranslator\\Bundle\\Tests\\')) {
                    $parts[] = $class . '::' . $frame['function'] . self::flatten($frame['args'] ?? [], 0);
                }
            }
        }

        return implode("\n", $parts);
    }

    private static function flatten(mixed $value, int $depth): string
    {
        return match (true) {
            \is_array($value) => $depth >= self::MAX_DEPTH ? '[…]' : '[' . implode(', ', array_map(
                static fn(int|string $key, mixed $item): string => $key . ' => ' . self::flatten($item, $depth + 1),
                array_keys($value),
                $value,
            )) . ']',
            \is_object($value) => $value::class,
            \is_scalar($value) => (string) $value,
            default => get_debug_type($value),
        };
    }
}
