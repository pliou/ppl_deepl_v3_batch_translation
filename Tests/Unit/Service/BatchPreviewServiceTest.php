<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\BatchSelection;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\FieldOperation;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\PermissionResult;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\PreflightItem;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\PreflightPlan;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\TranslationBatchRequest;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\TranslationBatchResult;
use Ppl\PplDeeplV3BatchTranslation\Domain\Enum\TranslationMode;
use Ppl\PplDeeplV3BatchTranslation\Service\BatchPreviewService;
use Ppl\PplDeeplV3BatchTranslation\Service\TranslationProviderInterface;
use Ppl\PplDeeplV3BatchTranslation\Service\TranslationRequestBuilder;

final class BatchPreviewServiceTest extends TestCase
{
    public function testProviderErrorsPreventCleanPreview(): void
    {
        $service = new BatchPreviewService(new TranslationRequestBuilder(), new PreviewFailingProvider());
        $preview = $service->buildPreview($this->plan(), 'DE', 'EN-US');

        self::assertContains('Provider failed.', $preview['errors']);
    }

    public function testMissingTranslatedValuesBecomePreviewErrors(): void
    {
        $service = new BatchPreviewService(new TranslationRequestBuilder(), new PreviewPartialProvider());
        $preview = $service->buildPreview($this->plan(), 'DE', 'EN-US');

        self::assertNotSame([], $preview['errors']);
        self::assertStringContainsString('Missing DeepL suggestion', implode("\n", $preview['errors']));
    }

    public function testElementPreviewScopeUsesBaseUidForNonDefaultSourceRecords(): void
    {
        $provider = new PreviewEchoProvider();
        $service = new BatchPreviewService(new TranslationRequestBuilder(), $provider);
        $preview = $service->buildPreview($this->nonDefaultSourcePlan(), 'EN', 'FR', null, false, 'element', 10);

        self::assertSame(1, $provider->calls);
        self::assertSame('Translated source text', $preview['plan']->items[0]->fieldOperations[0]->translatedValue);
    }

    private function plan(): PreflightPlan
    {
        $selection = new BatchSelection('main', 0, 1, TranslationMode::TranslateMissingOnly);

        return new PreflightPlan($selection, [
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
                    new FieldOperation('pages:1:title', 'pages', 1, 0, 1, 'title', 'Title', 'Hallo', '', 'translate'),
                ],
                [],
                1
            ),
        ]);
    }

    private function nonDefaultSourcePlan(): PreflightPlan
    {
        $selection = new BatchSelection('main', 1, 2, TranslationMode::TranslateMissingOnly);

        return new PreflightPlan($selection, [
            new PreflightItem(
                'tt_content:10',
                'element',
                'tt_content',
                99,
                0,
                1,
                'Element 10',
                'missing',
                'create',
                PermissionResult::allowed(),
                [
                    new FieldOperation('tt_content:10:header', 'tt_content', 99, 0, 1, 'header', 'Header', 'Source text', '', 'translate'),
                ],
                [],
                10
            ),
        ]);
    }
}

final class PreviewFailingProvider implements TranslationProviderInterface
{
    public function translateBatch(TranslationBatchRequest $request): TranslationBatchResult
    {
        return new TranslationBatchResult([], ['Provider failed.']);
    }
}

final class PreviewPartialProvider implements TranslationProviderInterface
{
    public function translateBatch(TranslationBatchRequest $request): TranslationBatchResult
    {
        return new TranslationBatchResult([], []);
    }
}

final class PreviewEchoProvider implements TranslationProviderInterface
{
    public int $calls = 0;

    public function translateBatch(TranslationBatchRequest $request): TranslationBatchResult
    {
        $this->calls++;
        $translations = [];
        foreach (array_keys($request->texts) as $operationId) {
            $translations[$operationId] = 'Translated source text';
        }

        return new TranslationBatchResult($translations, []);
    }
}
