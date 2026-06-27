<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service;

use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\FieldOperation;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

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
    /**
     * Execute a confirmed preview job. Pass $maxItems to process at most that many still-pending items
     * this run (chunked execution): the job is then left in the resumable `interrupted` state and a
     * follow-up run / scheduler tick continues it, so a huge job can run via CLI/cron without blocking
     * a web worker. With $maxItems = null the whole job is processed in one pass (default UI behaviour).
     * Already-handled items (processed_at > 0) are always skipped, so an interrupted job resumes cleanly.
     */
    public function executePreviewJob(int $jobUid, bool $makeWrittenRecordsVisible = true, ?int $maxItems = null): array
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
        // Accept a fresh `previewed` job or a resumable `interrupted` one (crash/reclaim or chunk pause).
        if (!in_array($status, ['previewed', 'interrupted'], true)) {
            return $this->result('error', 'Execute requires a confirmed translation preview job.', []);
        }

        if ($mode === 'preview_only') {
            return $this->result('warning', 'Preview-only mode does not write records.', []);
        }

        // Free jobs stuck in "running" from a crashed earlier execution before claiming.
        $this->jobLogger->reclaimStaleRunningJobs();

        if (!$this->jobLogger->claimPreviewJobForExecution($jobUid)) {
            return $this->result('error', 'Preview job is already running or no longer previewed.', []);
        }

        $counters = [
            'processed_items' => 0,
            'blocked_items' => 0,
            'skipped_items' => 0,
            'failed_items' => 0,
            'translated_items' => 0,
            'made_visible_items' => 0,
        ];

        $handledThisRun = 0;
        foreach ($stored['items'] as $storedItem) {
            // Resume: skip items already handled in this or an earlier (interrupted) run.
            if ((int)($storedItem['processed_at'] ?? 0) > 0) {
                continue;
            }
            // Chunk: stop after $maxItems still-pending items this run; the rest resumes next run.
            if ($maxItems !== null && $handledThisRun >= $maxItems) {
                break;
            }
            $handledThisRun++;
            // Renew the job lease periodically so a legitimately long run is not reclaimed as crashed.
            if ($handledThisRun % 20 === 0) {
                $this->jobLogger->heartbeat($jobUid);
            }
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

            $baseUid = (int)($item['baseUid'] ?? $item['sourceUid'] ?? 0);
            $translationSourceUid = (int)($item['sourceUid'] ?? 0);
            $targetUid = (int)($item['targetUid'] ?? 0);
            $targetWasCreated = false;

            // Serialize work on the same (table, base record, target language) so two different jobs
            // cannot localize/overwrite the same translation concurrently. The lock is held across the
            // whole resolve+write+compensate cycle (incl. the failure handler), so a rollback can only
            // ever touch a record this run created while still holding the lock.
            $recordLock = $this->acquireRecordLock((string)$item['table'], $baseUid, (int)$job['target_language_id']);
            try {
                if ($targetUid <= 0) {
                    $localized = $this->localizationService->ensureLocalizedRecordWithOrigin(
                        (string)$item['table'],
                        $baseUid,
                        (int)$job['target_language_id'],
                        $translationSourceUid
                    );
                    $targetUid = $localized['uid'];
                    // Only compensate-delete a record THIS run actually created. A translation that
                    // appeared between preview and execution (another editor/job) is NOT ours to delete.
                    $targetWasCreated = $localized['created'];
                }

                $operations = $this->operationsForItem($item, $targetUid);
                if ($this->requiresTranslatedValues($mode, $item) && $operations === []) {
                    $counters['failed_items']++;
                    $this->compensateCreatedTarget($targetWasCreated, (string)$item['table'], $targetUid);
                    $this->jobLogger->markItemProcessed($itemUid, 'failed', 'No translated field values are available to write.', $targetUid, 'missing_translation_values');
                    continue;
                }

                $writeResult = $this->writer->write($operations);
                if ($writeResult['errors'] !== []) {
                    $counters['failed_items']++;
                    $this->compensateCreatedTarget($targetWasCreated, (string)$item['table'], $targetUid);
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
                        $this->compensateCreatedTarget($targetWasCreated, (string)$item['table'], $targetUid);
                        $this->jobLogger->markItemProcessed($itemUid, 'failed', implode('; ', $visibilityErrors), $targetUid, 'visibility_failed');
                        continue;
                    }
                    $counters['made_visible_items']++;
                }

                if ((string)$item['table'] === 'pages' && $this->hasWrittenTitleOperation($operations)) {
                    $slugErrors = $this->writer->regeneratePageSlug($targetUid);
                    if ($slugErrors !== []) {
                        $counters['failed_items']++;
                        $this->compensateCreatedTarget($targetWasCreated, (string)$item['table'], $targetUid);
                        $this->jobLogger->markItemProcessed($itemUid, 'failed', implode('; ', $slugErrors), $targetUid, 'slug_failed');
                        continue;
                    }
                }

                $counters['processed_items']++;
                $counters['translated_items'] += $writeResult['writtenFields'] > 0 ? 1 : 0;
                $this->jobLogger->markItemProcessed($itemUid, $writeResult['writtenFields'] > 0 ? 'translated' : 'localized', '', $targetUid);
            } catch (\Throwable $exception) {
                $counters['failed_items']++;
                $this->compensateCreatedTarget($targetWasCreated, (string)$item['table'], $targetUid);
                $this->jobLogger->markItemProcessed($itemUid, 'failed', $exception->getMessage(), 0, 'execution_exception');
            } finally {
                $this->releaseRecordLock($recordLock);
            }
        }

        // Items still pending (a chunk pause, or a partially processed run): leave the job in the
        // resumable `interrupted` state for the next execute run / scheduler tick.
        $remaining = $this->jobLogger->countUnprocessedItems($jobUid);
        if ($remaining > 0) {
            $this->jobLogger->pauseForResume($jobUid);

            return $this->result(
                'info',
                sprintf(
                    'Processed %d item(s) this run; %d still pending. Re-run execute (CLI/scheduler) to continue.',
                    $handledThisRun,
                    $remaining
                ),
                $counters
            );
        }

        // All items handled: persist the authoritative totals recomputed from the item statuses
        // (correct whether the job ran in one pass, was resumed, or ran in chunks) and finish.
        $totals = $this->jobLogger->countExecutedItems($jobUid);
        $this->jobLogger->markFinished($jobUid, $totals);
        $type = $totals['failed_items'] > 0 ? 'warning' : 'success';

        return $this->result(
            $type,
            sprintf(
                'Execution finished: %d processed, %d translated, %d visible, %d blocked, %d skipped, %d failed.',
                $totals['processed_items'],
                $totals['translated_items'],
                $counters['made_visible_items'],
                $totals['blocked_items'],
                $totals['skipped_items'],
                $totals['failed_items']
            ),
            $totals + ['made_visible_items' => $counters['made_visible_items']]
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

        $staleField = $this->detectStaleField($item, $table, $sourceRecord);
        if ($staleField !== '') {
            return ['status' => 'blocked', 'errorCode' => 'stale_record', 'message' => 'Source or target changed since preview (' . $staleField . '); re-run the preview before writing.'];
        }

        return ['status' => '', 'errorCode' => '', 'message' => ''];
    }

    /**
     * Stale-write guard: the previewed source/target values must still match the live record,
     * otherwise an editor changed them after the preview and the batch would overwrite that work.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $sourceRecord
     */
    private function detectStaleField(array $item, string $table, array $sourceRecord): string
    {
        $fieldOperations = is_array($item['fieldOperations'] ?? null) ? $item['fieldOperations'] : [];
        $targetUid = (int)($item['targetUid'] ?? 0);
        $targetRecord = $targetUid > 0 ? $this->recordMappingService->fetchRecord($table, $targetUid) : null;

        foreach ($fieldOperations as $operationData) {
            if (!is_array($operationData)) {
                continue;
            }
            $field = (string)($operationData['field'] ?? '');
            if ($field === '') {
                continue;
            }
            if (array_key_exists($field, $sourceRecord)
                && $this->normalizeValue((string)$sourceRecord[$field]) !== $this->normalizeValue((string)($operationData['sourceValue'] ?? ''))
            ) {
                return $field . ' (source)';
            }
            if (is_array($targetRecord)
                && array_key_exists($field, $targetRecord)
                && $this->normalizeValue((string)$targetRecord[$field]) !== $this->normalizeValue((string)($operationData['targetValue'] ?? ''))
            ) {
                return $field . ' (target)';
            }
        }

        return '';
    }

    private function normalizeValue(string $value): string
    {
        return trim($value);
    }

    /**
     * Delete a target record this run just created when a later step fails, so a re-run starts
     * clean instead of leaving a half-written localization behind.
     */
    private function compensateCreatedTarget(bool $targetWasCreated, string $table, int $targetUid): void
    {
        if ($targetWasCreated && $targetUid > 0) {
            $this->writer->deleteRecord($table, $targetUid);
        }
    }

    /**
     * Acquire an exclusive lock for one (table, base record, target language) target so two batch
     * jobs cannot localize/write the same translation at the same time. Returns null when locking is
     * unavailable on the platform (the batch then proceeds without it rather than stalling).
     */
    private function acquireRecordLock(string $table, int $baseUid, int $targetLanguageId): ?LockingStrategyInterface
    {
        try {
            $lock = GeneralUtility::makeInstance(LockFactory::class)->createLocker(
                'ppl_batch_loc_' . $table . '_' . $baseUid . '_' . $targetLanguageId
            );
            $lock->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE);

            return $lock;
        } catch (\Throwable) {
            return null;
        }
    }

    private function releaseRecordLock(?LockingStrategyInterface $lock): void
    {
        if (!$lock instanceof LockingStrategyInterface) {
            return;
        }
        try {
            $lock->release();
        } catch (\Throwable) {
            // Best effort; a file/semaphore lock is also released when the process ends.
        }
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
