<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Domain\Dto;

final class TranslationBatchResult
{
    /**
     * @param array<string, string> $translations
     * @param string[] $errors
     */
    public function __construct(
        public readonly array $translations,
        public readonly array $errors = []
    ) {}

    public static function fromError(TranslationBatchRequest $request, string $error): self
    {
        return new self([], [sprintf('%s -> %s: %s', $request->sourceLanguage, $request->targetLanguage, $error)]);
    }
}
