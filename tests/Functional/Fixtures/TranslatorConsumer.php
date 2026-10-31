<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Functional\Fixtures;

use HtmlTranslator\HtmlTranslator;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * An application service asking for translators the three documented ways (ARCHITECTURE §4).
 */
final readonly class TranslatorConsumer
{
    public function __construct(
        public HtmlTranslator $default,
        // By argument name: the Symfony 7.4 way; Symfony 8.1 still resolves it but deprecates it in favour of #[Target].
        public HtmlTranslator $secondTranslator,
        #[Target('second')]
        public HtmlTranslator $byTarget,
    ) {}
}
