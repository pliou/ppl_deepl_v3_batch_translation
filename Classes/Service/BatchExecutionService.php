<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service;

use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\FieldOperation;

final class BatchExecutionService
{
    public function __construct(
        private readonly BatchJobLogger $jobLogger,
        private readonly BatchJobAccessGuard $jobAccessGuard,
        private readonly BatchRecordMappingService $recordMappingService,
        private readonly BatchPermissionService $permissionService,
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
        if (!$this->jobAccessGuard->canAccessStoredJob($job)) {
            return $this->result('error', $this->jobAccessGuard->accessDeniedMessage(), []);
        }

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
                $this->jobLogger->markItemProcessed($itemUid, 'failed', 'Stored preview item could not be decoded.', 0, 'decode_failed');
                continue;
            }

            if (!$this->isItemWritable($item)) {
                $counters['blocked_items']++;
                $this->jobLogger->markItemProcessed($itemUid, 'blocked', $this->itemErrors($item), 0, $this->itemErrorCode($item));
                continue;
            }

            if ((string)($item['recordAction'] ?? '') === 'skip') {
                $counters['skipped_items']++;
                $this->jobLogger->markItemProcessed($itemUid, 'skipped');
                continue;
            }

            $freshValidation = $this->validateFreshItem($job, $item);
            if ($freshValidation['errorCode'] !== '') {
                $counters[$freshValidation['status'] === 'blocked' ? 'blocked_items' : 'failed_items']++;
                $this->jobLogger->markItemProcessed(
                    $itemUid,
                    $freshValidation['status'],
                    $freshValidation['message'],
                    0,
                    $freshValidation['errorCode']
                );
                continue;
            }

            if ($this->requiresTranslatedValues($mode, $item) && !$this->hasTranslatedValues($item)) {
                $counters['failed_items']++;
                $this->jobLogger->markItemProcessed($itemUid, 'failed', 'Expected translated field values are missing.', 0, 'missing_translation_values');
                continue;
            }

            try {
                $baseUid = (int)($item['baseUid'] ?? $item['sourceUid'] ?? 0);
                $translationSourceUid = (int)($item['sourceUid'] ?? 0);
                $targetUid = (int)($item['targetUid'] ?? 0);
                $targetWasCreated = $targetUid <= 0;
                if ($targetUid <= 0) {
                    $targetUid = $this->localizationService->ensureLocalizedRecord(
                        (string)$item['table'],
                        $baseUid,
                        (int)$job['target_language_id'],
                        $translationSourceUid
                    );
                }

                $operations = $this->operationsForItem($item, $targetUid);
                if ($this->requiresTranslatedValues($mode, $item) && $operations === []) {
                    $counters['failed_items']++;
                    $this->jobLogger->markItemProcessed($itemUid, 'failed', 'No translated field values are available to write.', $targetUid, 'missing_translation_values');
                    continue;
                }

                $writeResult = $this->writer->write($operations);
                if ($writeResult['errors'] !== []) {
                    $counters['failed_items']++;
                    $this->jobLogger->markItemProcessed($itemUid, 'failed', implode('; ', $writeResult['errors']), $targetUid, 'datahandler_write_failed');
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
                        $this->jobLogger->markItemProcessed($itemUid, 'failed', implode('; ', $visibilityErrors), $targetUid, 'visibility_failed');
                        continue;
                    }
                    $counters['made_visible_items']++;
                }

                if ((string)$item['table'] === 'pages' && $this->hasWrittenTitleOperation($operations)) {
                    $slugErrors = $this->writer->regeneratePageSlug($targetUid);
                    if ($slugErrors !== []) {
                        $counters['failed_items']++;
                        $this->jobLogger->markItemProcessed($itemUid, 'failed', implode('; ', $slugErrors), $targetUid, 'slug_failed');
                        continue;
                    }
                }

                $counters['processed_items']++;
                $counters['translated_items'] += $writeResult['writtenFields'] > 0 ? 1 : 0;
                $this->jobLogger->markItemProcessed($itemUid, $writeResult['writtenFields'] > 0 ? 'translated' : 'localized', '', $targetUid);
            } catch (\Throwable $exception) {
                $counters['failed_items']++;
                $this->jobLogger->markItemProcessed($itemUid, 'failed', $exception->getMessage(), 0, 'execution_exception');
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
     */
    private function itemErrorCode(array $item): string
    {
        foreach (is_array($item['errors'] ?? null) ? $item['errors'] : [] as $error) {
            if (str_contains((string)$error, 'source language record')) {
                return 'source_missing';
            }
        }

        $permission = $item['permission'] ?? [];

        return is_array($permission) && empty($permission['allowed']) ? 'permission_denied' : 'preflight_error';
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $item
     * @return array{status: string, errorCode: string, message: string}
     */
    private function validateFreshItem(array $job, array $item): array
    {
        $table = (string)($item['table'] ?? '');
        $baseUid = (int)($item['baseUid'] ?? $item['sourceUid'] ?? 0);
        $sourceUid = (int)($item['sourceUid'] ?? 0);
        $sourceLanguageId = (int)($job['source_language_id'] ?? 0);
        $targetLanguageId = (int)($job['target_language_id'] ?? 0);
        $sourcePageUid = (int)($item['sourcePageUid'] ?? 0);
        $baseRecord = $this->recordMappingService->fetchRecord($table, $baseUid);
        if ($baseRecord === null) {
            return ['status' => 'failed', 'errorCode' => 'base_missing', 'message' => 'Base record no longer exists.'];
        }

        $sourceRecord = $sourceLanguageId <= 0 ? $baseRecord : $this->recordMappingService->fetchRecord($table, $sourceUid);
        if ($sourceRecord === null || (int)($sourceRecord['sys_language_uid'] ?? -1) !== $sourceLanguageId) {
            return ['status' => 'blocked', 'errorCode' => 'source_missing', 'message' => 'Selected source language record is missing.'];
        }

        $permission = $this->permissionService->checkRecordAccess($table, $baseRecord, $sourcePageUid, $targetLanguageId);
        if (!$permission->allowed) {
            return ['status' => 'blocked', 'errorCode' => 'permission_denied', 'message' => implode('; ', $permission->reasons)];
        }

        return ['status' => '', 'errorCode' => '', 'message' => ''];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function requiresTranslatedValues(string $mode, array $item): bool
    {
        return $mode !== 'create_missing_records_only'
            && (string)($item['recordAction'] ?? '') !== 'skip';
    }

    /**
     * @param array<string, mixed> $item
     */
    private function hasTranslatedValues(array $item): bool
    {
        foreach (is_array($item['fieldOperations'] ?? null) ? $item['fieldOperations'] : [] as $operationData) {
            if (is_array($operationData) && trim((string)($operationData['translatedValue'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
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
