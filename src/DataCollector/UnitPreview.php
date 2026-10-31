<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\DataCollector;

/**
 * One unit of a recorded batch, as plain text truncated for the panel.
 *
 * @internal
 */
final readonly class UnitPreview
{
    public function __construct(
        public string $id,
        public string $source,
        public ?string $translation,
        public ?string $failure,
    ) {}
}
