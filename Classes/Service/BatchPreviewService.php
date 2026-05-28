<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service;

use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\FieldOperation;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\PermissionResult;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\PreflightItem;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\PreflightPlan;

final class BatchPreviewService
{
    public function __construct(
        private readonly TranslationRequestBuilder $requestBuilder,
        private readonly TranslationProviderInterface $translationProvider
    ) {}

    /**
     * @return array{plan: PreflightPlan, errors: string[]}
     */
    public function buildPreview(
        PreflightPlan $plan,
        string $sourceLanguage,
        string $targetLanguage,
        ?PreflightPlan $cachedPlan = null,
        bool $forceRetranslate = false,
        string $scopeType = 'batch',
        int $scopeUid = 0
    ): array
    {
        $allowedItemIds = $this->allowedItemIds($plan, $scopeType, $scopeUid);
        if ($allowedItemIds !== null) {
            $plan = $this->filterPlanByItemIds($plan, $allowedItemIds);
        }

        if (!$plan->selection->mode->translatesText()) {
            return [
                'plan' => $plan,
                'errors' => [],
            ];
        }

        if (!$forceRetranslate && $cachedPlan instanceof PreflightPlan) {
            $plan = $this->applyCachedTranslations($plan, $cachedPlan);
        }

        $translations = [];
        $errors = [];
        foreach ($this->requestBuilder->buildRequests($plan, $sourceLanguage, $targetLanguage) as $request) {
            $result = $this->translationProvider->translateBatch($request);
            foreach ($result->translations as $operationId => $translation) {
                $translations[$operationId] = $translation;
            }
            foreach ($result->errors as $error) {
                $errors[] = $error;
            }
        }

        $items = [];
        foreach ($plan->items as $item) {
            $operations = [];
            foreach ($item->fieldOperations as $operation) {
                $operations[] = isset($translations[$operation->operationId])
                    ? $operation->withTranslatedValue((string)$translations[$operation->operationId])
                    : $operation;
            }

            $items[] = new PreflightItem(
                $item->itemId,
                $item->itemType,
                $item->table,
                $item->sourceUid,
                $item->targetUid,
                $item->sourcePageUid,
                $item->label,
                $item->status,
                $item->recordAction,
                new PermissionResult($item->permission->allowed, $item->permission->reasons),
                $operations,
                $item->errors
            );
        }

        return [
            'plan' => new PreflightPlan($plan->selection, $items, $plan->messages, $plan->jobUid),
            'errors' => array_values(array_unique($errors)),
        ];
    }

    private function applyCachedTranslations(PreflightPlan $plan, PreflightPlan $cachedPlan): PreflightPlan
    {
        $cachedTranslations = [];
        foreach ($cachedPlan->items as $cachedItem) {
            foreach ($cachedItem->fieldOperations as $cachedOperation) {
                if (trim($cachedOperation->translatedValue) !== '') {
                    $cachedTranslations[$this->cacheKey($cachedOperation)] = $cachedOperation->translatedValue;
                }
            }
        }
        if ($cachedTranslations === []) {
            return $plan;
        }

        $items = [];
        foreach ($plan->items as $item) {
            $operations = [];
            foreach ($item->fieldOperations as $operation) {
                $cacheKey = $this->cacheKey($operation);
                $operations[] = isset($cachedTranslations[$cacheKey])
                    ? $operation->withTranslatedValue((string)$cachedTranslations[$cacheKey])
                    : $operation;
            }
            $items[] = new PreflightItem(
                $item->itemId,
                $item->itemType,
                $item->table,
                $item->sourceUid,
                $item->targetUid,
                $item->sourcePageUid,
                $item->label,
                $item->status,
                $item->recordAction,
                new PermissionResult($item->permission->allowed, $item->permission->reasons),
                $operations,
                $item->errors
            );
        }

        return new PreflightPlan($plan->selection, $items, $plan->messages, $plan->jobUid);
    }

    /**
     * @return array<string, bool>|null
     */
    private function allowedItemIds(PreflightPlan $plan, string $scopeType, int $scopeUid): ?array
    {
        if ($scopeType === 'batch' || $scopeUid <= 0) {
            return null;
        }

        $allowed = [];
        foreach ($plan->items as $item) {
            $include = match ($scopeType) {
                'page' => $item->sourcePageUid === $scopeUid || ($item->table === 'pages' && $item->sourceUid === $scopeUid),
                'element' => $item->table === 'tt_content' && $item->sourceUid === $scopeUid,
                default => true,
            };
            if ($include) {
                $allowed[$item->itemId] = true;
            }
        }

        return $allowed;
    }

    /**
     * @param array<string, bool> $allowedItemIds
     */
    private function filterPlanByItemIds(PreflightPlan $plan, array $allowedItemIds): PreflightPlan
    {
        $items = array_values(array_filter(
            $plan->items,
            static fn(PreflightItem $item): bool => isset($allowedItemIds[$item->itemId])
        ));

        return new PreflightPlan($plan->selection, $items, $plan->messages, $plan->jobUid);
    }

    private function cacheKey(FieldOperation $operation): string
    {
        return implode(':', [
            $operation->table,
            (string)$operation->sourceUid,
            $operation->field,
            hash('sha256', $operation->sourceValue),
        ]);
    }
}
