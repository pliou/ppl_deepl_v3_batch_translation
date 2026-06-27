<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Domain\Dto;

use Ppl\PplDeeplV3BatchTranslation\Domain\Enum\TranslationMode;

final class BatchSelection
{
    /**
     * @param PageSelection[] $selectedPages
     * @param SubtreeSelection[] $selectedSubtrees
     * @param ElementSelection[] $selectedElements
     * @param int[] $excludedPageUids
     * @param int[] $excludedElementUids
     */
    public function __construct(
        public readonly string $siteIdentifier,
        public readonly int $sourceLanguageId,
        public readonly int $targetLanguageId,
        public readonly TranslationMode $mode,
        public readonly array $selectedPages = [],
        public readonly array $selectedSubtrees = [],
        public readonly array $selectedElements = [],
        public readonly array $excludedPageUids = [],
        public readonly array $excludedElementUids = [],
        public readonly ?string $glossaryId = null,
        public readonly string $styleRuleId = '',
        public readonly string $customInstructions = ''
    ) {}

    public function isEmpty(): bool
    {
        return $this->selectedPages === [] && $this->selectedSubtrees === [] && $this->selectedElements === [];
    }

    public function hasExcludedPage(int $pageUid): bool
    {
        return in_array($pageUid, $this->excludedPageUids, true);
    }

    public function hasExcludedElement(int $contentUid): bool
    {
        return in_array($contentUid, $this->excludedElementUids, true);
    }

    /**
     * @return string[]
     */
    public function customInstructionLines(): array
    {
        $lines = preg_split('/\R/', trim($this->customInstructions)) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), static fn(string $line): bool => $line !== ''));

        return array_slice(array_values(array_unique($lines)), 0, 10);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'siteIdentifier' => $this->siteIdentifier,
            'sourceLanguageId' => $this->sourceLanguageId,
            'targetLanguageId' => $this->targetLanguageId,
            'mode' => $this->mode->value,
            'selectedPages' => array_map(static fn(PageSelection $selection): array => $selection->toArray(), $this->selectedPages),
            'selectedSubtrees' => array_map(static fn(SubtreeSelection $selection): array => $selection->toArray(), $this->selectedSubtrees),
            'selectedElements' => array_map(static fn(ElementSelection $selection): array => $selection->toArray(), $this->selectedElements),
            'excludedPageUids' => $this->excludedPageUids,
            'excludedElementUids' => $this->excludedElementUids,
            'glossaryId' => $this->glossaryId,
            'styleRuleId' => $this->styleRuleId,
            'customInstructions' => $this->customInstructions,
        ];
    }
}
