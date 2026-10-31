<?php

declare(strict_types=1);

namespace HtmlTranslator\Bundle\Tests\Functional\Fixtures;

use HtmlTranslator\Bundle\TranslatorRegistry;
use HtmlTranslator\Domain\Locale;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An application controller translating during a request, for the profiler scenario.
 */
final readonly class TranslateController
{
    public const string HTML = '<p>Hello <b>world</b></p><p><script>alert(1)</script> &lt;script&gt;alert(2)&lt;/script&gt;</p>';

    public function __construct(
        private TranslatorRegistry $translators,
    ) {}

    public function __invoke(Request $request): Response
    {
        $translator = $this->translators->get($request->query->has('provider') ? $request->query->getString('provider') : null);

        return new Response($translator->translate(self::HTML, Locale::fromString('de'))->html());
    }
}
