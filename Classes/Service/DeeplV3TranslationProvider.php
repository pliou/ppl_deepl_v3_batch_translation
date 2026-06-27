<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service;

use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\TranslationBatchRequest;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\TranslationBatchResult;
use Ppl\PplDeeplV3Requests\Service\TranslationGatewayInterface;

final class DeeplV3TranslationProvider implements TranslationProviderInterface
{
    public function __construct(
        private readonly TranslationGatewayInterface $translationGateway
    ) {}

    public function translateBatch(TranslationBatchRequest $request): TranslationBatchResult
    {
        try {
            $ids = array_keys($request->texts);
            $translatedTexts = $this->translationGateway->translateTexts(
                array_values($request->texts),
                $request->sourceLanguage,
                $request->targetLanguage,
                $request->glossaryId,
                $request->styleRuleId,
                $request->customInstructions,
                $request->tagHandling,
                $request->context
            );
            $translations = [];

            if (count($translatedTexts) !== count($ids)) {
                throw new \RuntimeException(sprintf(
                    'Translation gateway returned %d translations for %d request texts.',
                    count($translatedTexts),
                    count($ids)
                ));
            }

            foreach ($ids as $index => $id) {
                $translations[$id] = (string)$translatedTexts[$index];
            }

            return new TranslationBatchResult($translations, []);
        } catch (\Throwable $exception) {
            return TranslationBatchResult::fromError($request, $exception->getMessage());
        }
    }
}
