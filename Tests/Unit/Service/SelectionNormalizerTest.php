<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ppl\PplDeeplV3BatchTranslation\Domain\Enum\TranslationMode;
use Ppl\PplDeeplV3BatchTranslation\Service\SelectionNormalizer;

final class SelectionNormalizerTest extends TestCase
{
    public function testNormalizesMixedSelectionAndRemovesDuplicates(): void
    {
        $selection = (new SelectionNormalizer())->fromRequest([
            'site_identifier' => 'main',
            'source_language_id' => '0',
            'target_language_id' => '2',
            'translation_mode' => 'retranslate_selected',
            'selected_pages' => ['123', '123', '0'],
            'selected_subtree_pages' => ['200'],
            'selected_elements' => ['455:123', '455:123', '511:9'],
            'excluded_pages' => ['300', '300', '0'],
            'excluded_elements' => ['455:123', '455:123', '700:300'],
            'glossary_id' => 'glossary-1',
            'style_rule_id' => 'style-1',
            'custom_instructions' => "Formal\nFormal\nAvoid slang",
        ]);

        self::assertSame('main', $selection->siteIdentifier);
        self::assertSame(0, $selection->sourceLanguageId);
        self::assertSame(2, $selection->targetLanguageId);
        self::assertSame(TranslationMode::RetranslateSelected, $selection->mode);
        self::assertCount(1, $selection->selectedPages);
        self::assertTrue($selection->selectedPages[0]->includeElements);
        self::assertCount(1, $selection->selectedSubtrees);
        self::assertCount(2, $selection->selectedElements);
        self::assertSame([300], $selection->excludedPageUids);
        self::assertSame([455, 700], $selection->excludedElementUids);
        self::assertSame(['Formal', 'Avoid slang'], $selection->customInstructionLines());
    }
}
