<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\FieldOperation;
use Ppl\PplDeeplV3BatchTranslation\Service\TranslationValueLimiter;

final class TranslationValueLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TCA']['pages']['columns']['title']['config']['max'] = 5;
        $GLOBALS['TCA']['tt_content']['columns']['bodytext']['config']['max'] = 5;
    }

    public function testLimitsPlainValues(): void
    {
        $operation = new FieldOperation('pages:1:title', 'pages', 1, 0, 1, 'title', 'Title', 'Hello world', '', 'translate', '', 'Hello world');

        self::assertSame('Hello', (new TranslationValueLimiter())->limit($operation));
    }

    public function testDoesNotBlindlyLimitHtmlValues(): void
    {
        $operation = new FieldOperation('tt_content:1:bodytext', 'tt_content', 1, 0, 1, 'bodytext', 'Text', '<p>Hello world</p>', '', 'translate', 'html', '<p>Hello world</p>');

        self::assertSame('<p>Hello world</p>', (new TranslationValueLimiter())->limit($operation));
    }
}
