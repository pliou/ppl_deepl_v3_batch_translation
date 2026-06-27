<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service;

use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\FieldOperation;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\PreflightPlan;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\TranslationBatchRequest;
use Ppl\PplDeeplV3Requests\Domain\Dto\DeepLRequestContext;
use Ppl\PplDeeplV3Requests\Domain\Dto\RetryPolicy;
use Ppl\PplDeeplV3Requests\Service\DeepLRequestContextFactory;

final class TranslationRequestBuilder
{
    public function __construct(
        private readonly ?DeepLRequestContextFactory $contextFactory = null
    ) {}

    /**
     * @return TranslationBatchRequest[]
     */
    /**
     * @param array<string, bool>|null $allowedOperationIds
     */
    public function buildRequests(PreflightPlan $plan, string $sourceLanguage, string $targetLanguage, ?array $allowedOperationIds = null): array
    {
        if (!$plan->selection->mode->translatesText()) {
            return [];
        }

        $groups = [];
        foreach ($plan->writableFieldOperations() as $operation) {
            if (!$operation->needsTranslation() || trim($operation->translatedValue) !== '') {
                continue;
            }
            if ($allowedOperationIds !== null && !isset($allowedOperationIds[$operation->operationId])) {
                continue;
            }

            $tagHandling = $this->tagHandlingForOperation($operation);
            $key = implode('|', [
                $sourceLanguage,
                $targetLanguage,
                (string)$plan->selection->glossaryId,
                $plan->selection->styleRuleId,
                $tagHandling,
                md5(json_encode($plan->selection->customInstructionLines(), JSON_THROW_ON_ERROR)),
            ]);

            $groups[$key] ??= [
                'texts' => [],
                'tagHandling' => $tagHandling,
            ];
            $groups[$key]['texts'][$operation->operationId] = $operation->sourceValue;
        }

        $requests = [];
        foreach ($groups as $group) {
            $requests[] = new TranslationBatchRequest(
                $sourceLanguage,
                $targetLanguage,
                $group['texts'],
                $plan->selection->glossaryId,
                $plan->selection->styleRuleId,
                $group['tagHandling'],
                $plan->selection->customInstructionLines(),
                $this->createContext()
            );
        }

        return $requests;
    }

    private function tagHandlingForOperation(FieldOperation $operation): string
    {
        if ($operation->tagHandling !== '') {
            return $operation->tagHandling;
        }

        return $operation->field === 'bodytext' || str_contains($operation->sourceValue, '<') ? 'html' : '';
    }

    private function createContext(): DeepLRequestContext
    {
        if ($this->contextFactory instanceof DeepLRequestContextFactory) {
            return $this->contextFactory->createDefaultContext();
        }

        return new DeepLRequestContext(
            'test',
            'test',
            'https://api.deepl.com',
            'test',
            10.0,
            60.0,
            1048576,
            new RetryPolicy(1, 0, 0),
            'test'
        );
    }
}
