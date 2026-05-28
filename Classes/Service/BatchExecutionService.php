<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service;

use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\FieldOperation;

final class BatchExecutionService
{
    public function __construct(
        private readonly BatchJobLogger $jobLogger,
        private readonly RecordLocalizationService $localizationService,
        private readonly TranslationWriter $writer
    ) {}

    /**
     * @return array{type: string, text: string, counters: array<string, int>}
     */
    public function executePreviewJob(int $jobUid, bool $makeWrittenRecordsVisible = true): array
    {
        $stored = $this->jobLogger->loadJob($jobUid);
        if ($stored === null) {
            return $this->result('error', 'Preview job was not found.', []);
        }

        $job = $stored['job'];
        $status = (string)($job['status'] ?? '');
        $mode = (string)($job['translation_mode'] ?? '');
        if ($status !== 'previewed') {
            return $this->result('error', 'Execute requires a confirmed translation preview job.', []);
        }

        if ($mode === 'preview_only') {
            return $this->result('warning', 'Preview-only mode does not write records.', []);
        }

        $this->jobLogger->markStarted($jobUid);
        $counters = [
            'processed_items' => 0,
            'blocked_items' => 0,
            'skipped_items' => 0,
            'failed_items' => 0,
            'translated_items' => 0,
            'made_visible_items' => 0,
        ];

        foreach ($stored['items'] as $storedItem) {
            $itemUid = (int)$storedItem['uid'];
            $item = $this->decodeItem((string)($storedItem['options_json'] ?? ''));
            if ($item === null) {
                $counters['failed_items']++;
                $this->jobLogger->markItemProcessed($itemUid, 'failed', 'Stored preview item could not be decoded.');
                continue;
            }

            if (!$this->isItemWritable($item)) {
                $counters['blocked_items']++;
                $this->jobLogger->markItemProcessed($itemUid, 'blocked', $this->itemErrors($item));
                continue;
            }

            if ((string)($item['recordAction'] ?? '') === 'skip') {
                $counters['skipped_items']++;
                $this->jobLogger->markItemProcessed($itemUid, 'skipped');
                continue;
            }

            try {
                $targetUid = (int)($item['targetUid'] ?? 0);
                $targetWasCreated = $targetUid <= 0;
                if ($targetUid <= 0) {
                    $targetUid = $this->localizationService->ensureLocalizedRecord(
                        (string)$item['table'],
                        (int)$item['sourceUid'],
                        (int)$job['target_language_id']
                    );
                }

                $operations = $this->operationsForItem($item, $targetUid);
                $writeResult = $this->writer->write($operations);
                if ($writeResult['errors'] !== []) {
                    $counters['failed_items']++;
                    $this->jobLogger->markItemProcessed($itemUid, 'failed', implode('; ', $writeResult['errors']));
                    continue;
                }

                if (
                    $makeWrittenRecordsVisible
                    && $targetUid > 0
                    && (string)($item['status'] ?? '') !== 'hidden'
                ) {
                    $visibilityErrors = $this->writer->unhideRecord((string)$item['table'], $targetUid);
                    if ($visibilityErrors !== []) {
                        $counters['failed_items']++;
                        $this->jobLogger->markItemProcessed($itemUid, 'failed', implode('; ', $visibilityErrors), $targetUid);
                        continue;
                    }
                    $counters['made_visible_items']++;
                }

                if ((string)$item['table'] === 'pages' && $this->hasWrittenTitleOperation($operations)) {
                    $slugErrors = $this->writer->regeneratePageSlug($targetUid);
                    if ($slugErrors !== []) {
                        $counters['failed_items']++;
                        $this->jobLogger->markItemProcessed($itemUid, 'failed', implode('; ', $slugErrors), $targetUid);
                        continue;
                    }
                }

                $counters['processed_items']++;
                $counters['translated_items'] += $writeResult['writtenFields'] > 0 ? 1 : 0;
                $this->jobLogger->markItemProcessed($itemUid, $writeResult['writtenFields'] > 0 ? 'translated' : 'localized', '', $targetUid);
            } catch (\Throwable $exception) {
                $counters['failed_items']++;
                $this->jobLogger->markItemProcessed($itemUid, 'failed', $exception->getMessage());
            }
        }

        $persistedCounters = $counters;
        unset($persistedCounters['made_visible_items']);
        $this->jobLogger->markFinished($jobUid, $persistedCounters);
        $type = $counters['failed_items'] > 0 ? 'warning' : 'success';

        return $this->result(
            $type,
            sprintf(
                'Execution finished: %d processed, %d translated, %d visible, %d blocked, %d skipped, %d failed.',
                $counters['processed_items'],
                $counters['translated_items'],
                $counters['made_visible_items'],
                $counters['blocked_items'],
                $counters['skipped_items'],
                $counters['failed_items']
            ),
            $counters
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeItem(string $json): ?array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function isItemWritable(array $item): bool
    {
        $permission = $item['permission'] ?? [];

        return is_array($permission)
            && !empty($permission['allowed'])
            && ($item['errors'] ?? []) === [];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function itemErrors(array $item): string
    {
        $permission = $item['permission'] ?? [];
        $reasons = is_array($permission) && is_array($permission['reasons'] ?? null) ? $permission['reasons'] : [];
        $errors = is_array($item['errors'] ?? null) ? $item['errors'] : [];

        return implode('; ', array_merge($reasons, $errors));
    }

    /**
     * @param array<string, mixed> $item
     * @return FieldOperation[]
     */
    private function operationsForItem(array $item, int $targetUid): array
    {
        $operations = [];
        $fieldOperations = is_array($item['fieldOperations'] ?? null) ? $item['fieldOperations'] : [];
        foreach ($fieldOperations as $operationData) {
            if (!is_array($operationData)) {
                continue;
            }

            $operation = FieldOperation::fromArray($operationData)->withTargetUid($targetUid);
            if (trim($operation->translatedValue) !== '') {
                $operations[] = $operation;
            }
        }

        return $operations;
    }

    /**
     * @param FieldOperation[] $operations
     */
    private function hasWrittenTitleOperation(array $operations): bool
    {
        foreach ($operations as $operation) {
            if ($operation->table === 'pages' && $operation->field === 'title' && trim($operation->translatedValue) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, int> $counters
     * @return array{type: string, text: string, counters: array<string, int>}
     */
    private function result(string $type, string $text, array $counters): array
    {
        return [
            'type' => $type,
            'text' => $text,
            'counters' => $counters,
        ];
    }
}
