<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\BatchSelection;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\FieldOperation;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\PermissionResult;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\PreflightItem;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\PreflightPlan;
use Ppl\PplDeeplV3BatchTranslation\Domain\Enum\TranslationMode;
use Ppl\PplDeeplV3BatchTranslation\Service\TranslationRequestBuilder;

final class TranslationRequestBuilderTest extends TestCase
{
    public function testBuildsRequestsOnlyForWritableTranslatableOperations(): void
    {
        $selection = new BatchSelection(
            'main',
            0,
            1,
            TranslationMode::TranslateMissingOnly,
            [],
            [],
            [],
            [],
            [],
            null,
            '',
            ''
        );
        $plan = new PreflightPlan($selection, [
            new PreflightItem(
                'pages:1',
                'page',
                'pages',
                1,
                0,
                1,
                'Page 1',
                'missing',
                'create',
                PermissionResult::allowed(),
                [
                    new FieldOperation('pages:1:title', 'pages', 1, 0, 1, 'title', 'Title', 'Hello', '', 'translate'),
                    new FieldOperation('pages:1:bodytext', 'pages', 1, 0, 1, 'bodytext', '', '', '', 'skip'),
                ]
            ),
        ]);

        $requests = (new TranslationRequestBuilder())->buildRequests($plan, 'EN', 'DE');

        self::assertCount(1, $requests);
        self::assertSame(['pages:1:title' => 'Hello'], $requests[0]->texts);
    }
}
