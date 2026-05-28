<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Domain\Dto;

final class TranslationBatchRequest
{
    /**
     * @param array<string, string> $texts
     * @param string[] $customInstructions
     */
    public function __construct(
        public readonly string $sourceLanguage,
        public readonly string $targetLanguage,
        public readonly array $texts,
        public readonly ?string $glossaryId,
        public readonly string $styleRuleId,
        public readonly string $tagHandling,
        public readonly array $customInstructions
    ) {}
}
