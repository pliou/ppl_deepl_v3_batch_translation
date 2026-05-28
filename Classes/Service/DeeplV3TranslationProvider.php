<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service;

use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\TranslationBatchRequest;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\TranslationBatchResult;
use Ppl\PplDeeplV3Requests\Service\DeeplApiClientService;
use Ppl\PplDeeplV3Requests\Service\DeeplConfigurationService;

final class DeeplV3TranslationProvider implements TranslationProviderInterface
{
    public function __construct(
        private readonly DeeplApiClientService $apiClient,
        private readonly DeeplConfigurationService $configurationService
    ) {}

    public function translateBatch(TranslationBatchRequest $request): TranslationBatchResult
    {
        $authKey = $this->configurationService->getAuthKey();
        if ($authKey === '') {
            return TranslationBatchResult::fromError($request, 'No DeepL auth key is configured.');
        }

        try {
            $ids = array_keys($request->texts);
            $translatedTexts = $this->apiClient->translateTexts(
                $authKey,
                array_values($request->texts),
                $request->sourceLanguage,
                $request->targetLanguage,
                $request->glossaryId,
                $request->styleRuleId,
                $request->customInstructions,
                $request->tagHandling
            );
            $translations = [];

            foreach ($ids as $index => $id) {
                $translations[$id] = (string)($translatedTexts[$index] ?? '');
            }

            return new TranslationBatchResult($translations, []);
        } catch (\Throwable $exception) {
            return TranslationBatchResult::fromError($request, $exception->getMessage());
        }
    }
}
