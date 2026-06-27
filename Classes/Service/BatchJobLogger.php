<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service;

use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\BatchSelection;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\ElementSelection;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\PageSelection;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\PreflightItem;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\PreflightPlan;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\SubtreeSelection;
use Ppl\PplDeeplV3BatchTranslation\Domain\Enum\TranslationMode;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class BatchJobLogger
{
    private const JOB_TABLE = 'tx_ppldeeplv3batchtranslation_job';
    private const ITEM_TABLE = 'tx_ppldeeplv3batchtranslation_job_item';

    /** A "running" job whose started_at is older than this is treated as crashed and reclaimed. */
    private const LEASE_TIMEOUT_SECONDS = 3600;

    public function __construct(
        private readonly ConnectionPool $connectionPool
    ) {}

    public function createJobFromPlan(PreflightPlan $plan, string $status): int
    {
        $uid = $this->createJob($plan->selection, $status, $plan->counts());
        $this->replaceJobItems($uid, $plan->items);

        return $uid;
    }

    /**
     * @param array<string, int> $counts
     */
    public function createJob(BatchSelection $selection, string $status, array $counts = []): int
    {
        $now = time();
        $connection = $this->connectionPool->getConnectionForTable(self::JOB_TABLE);
        $connection->insert(self::JOB_TABLE, [
            'pid' => 0,
            'crdate' => $now,
            'tstamp' => $now,
            'site_identifier' => $selection->siteIdentifier,
            'source_language_id' => $selection->sourceLanguageId,
            'target_language_id' => $selection->targetLanguageId,
            'translation_mode' => $selection->mode->value,
            'status' => $status,
            'selected_scope_json' => json_encode($selection->toArray(), JSON_THROW_ON_ERROR),
            'options_json' => json_encode([
                'glossaryId' => $selection->glossaryId,
                'styleRuleId' => $selection->styleRuleId,
                'customInstructions' => $selection->customInstructions,
            ], JSON_THROW_ON_ERROR),
            'total_items' => (int)($counts['items'] ?? 0),
            'blocked_items' => (int)($counts['blocked'] ?? 0),
            'skipped_items' => (int)($counts['skipped'] ?? 0),
            'created_by' => (int)($GLOBALS['BE_USER']->user['uid'] ?? 0),
        ]);

        return (int)$connection->lastInsertId(self::JOB_TABLE);
    }

    /**
     * @param PreflightItem[] $items
     */
    public function replaceJobItems(int $jobUid, array $items): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::ITEM_TABLE);
        $connection->update(self::ITEM_TABLE, ['deleted' => 1], ['job_uid' => $jobUid]);
        $now = time();

        foreach ($items as $item) {
            $connection->insert(self::ITEM_TABLE, [
                'pid' => 0,
                'crdate' => $now,
                'tstamp' => $now,
                'job_uid' => $jobUid,
                'item_type' => $item->itemType,
                'source_table' => $item->table,
                'source_uid' => $item->sourceUid,
                'base_uid' => $item->effectiveBaseUid(),
                'target_uid' => $item->targetUid,
                'source_page_uid' => $item->sourcePageUid,
                'status' => $item->isBlocked() ? 'blocked' : $item->recordAction,
                'error_message' => implode('; ', array_merge($item->permission->reasons, $item->errors)),
                'error_code' => $item->isBlocked() ? $this->errorCodeForItem($item) : '',
                'source_hash' => hash('sha256', json_encode($item->toArray(), JSON_THROW_ON_ERROR)),
                'options_json' => json_encode($item->toArray(), JSON_THROW_ON_ERROR),
            ]);
        }
    }

    /**
     * @return array{job: array<string, mixed>, items: array<int, array<string, mixed>>}|null
     */
    public function loadJob(int $jobUid): ?array
    {
        if ($jobUid <= 0) {
            return null;
        }

        $jobConnection = $this->connectionPool->getConnectionForTable(self::JOB_TABLE);
        $job = $jobConnection->select(['*'], self::JOB_TABLE, ['uid' => $jobUid, 'deleted' => 0])->fetchAssociative();
        if (!is_array($job)) {
            return null;
        }

        $itemConnection = $this->connectionPool->getConnectionForTable(self::ITEM_TABLE);
        $items = $itemConnection->select(['*'], self::ITEM_TABLE, ['job_uid' => $jobUid, 'deleted' => 0], [], ['uid' => 'ASC'])->fetchAllAssociative();

        return [
            'job' => $job,
            'items' => $items,
        ];
    }

    public function loadPlan(int $jobUid): ?PreflightPlan
    {
        $stored = $this->loadJob($jobUid);
        if ($stored === null) {
            return null;
        }

        try {
            $selectionData = json_decode((string)($stored['job']['selected_scope_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($selectionData)) {
            return null;
        }

        $items = [];
        foreach ($stored['items'] as $storedItem) {
            try {
                $itemData = json_decode((string)($storedItem['options_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (is_array($itemData)) {
                $items[] = PreflightItem::fromArray($itemData);
            }
        }

        return new PreflightPlan($this->selectionFromArray($selectionData), $items, [], $jobUid);
    }

    public function updateJobStatus(int $jobUid, string $status, array $counters = []): void
    {
        $fields = array_merge(['status' => $status, 'tstamp' => time()], $counters);
        $this->connectionPool->getConnectionForTable(self::JOB_TABLE)->update(self::JOB_TABLE, $fields, ['uid' => $jobUid]);
    }

    public function discardJob(int $jobUid): void
    {
        if ($jobUid <= 0) {
            return;
        }

        $this->updateJobStatus($jobUid, 'discarded');
        $this->connectionPool->getConnectionForTable(self::ITEM_TABLE)->update(
            self::ITEM_TABLE,
            ['status' => 'discarded', 'tstamp' => time()],
            ['job_uid' => $jobUid, 'deleted' => 0]
        );
    }

    public function markStarted(int $jobUid): void
    {
        $now = time();
        $this->updateJobStatus($jobUid, 'running', [
            'started_at' => $now,
            'lease_token' => bin2hex(random_bytes(16)),
            'lease_renewed_at' => $now,
        ]);
    }

    /**
     * Keep a running job's lease fresh so a legitimately long-running execution (longer than the
     * lease window) is not mistaken for a crashed process and reclaimed mid-run.
     */
    public function heartbeat(int $jobUid): void
    {
        if ($jobUid <= 0) {
            return;
        }
        $now = time();
        $this->connectionPool->getConnectionForTable(self::JOB_TABLE)->update(
            self::JOB_TABLE,
            ['lease_renewed_at' => $now, 'tstamp' => $now],
            ['uid' => $jobUid, 'status' => 'running']
        );
    }

    /**
     * Reclaim jobs stuck in "running" longer than the lease window (a crashed process never
     * marks them finished). They become "interrupted" so they stop blocking and are cleaned up,
     * instead of hanging in "running" forever. A job is only reclaimed when BOTH its start AND its
     * last heartbeat are older than the lease window, so an actively heart-beating long run survives.
     */
    public function reclaimStaleRunningJobs(): int
    {
        $threshold = time() - self::LEASE_TIMEOUT_SECONDS;
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::JOB_TABLE);

        return (int)$queryBuilder
            ->update(self::JOB_TABLE)
            ->set('status', 'interrupted')
            ->set('tstamp', time())
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter('running')),
                $queryBuilder->expr()->lt('started_at', $queryBuilder->createNamedParameter($threshold, \PDO::PARAM_INT)),
                $queryBuilder->expr()->lt('lease_renewed_at', $queryBuilder->createNamedParameter($threshold, \PDO::PARAM_INT))
            )
            ->executeStatement();
    }

    /**
     * Atomically claim a job for (chunked) execution: a fresh `previewed` job OR an `interrupted` one
     * that is being resumed (after a crash/reclaim or a deliberate chunk pause). The atomic status
     * transition guarantees only one worker can own the job at a time.
     */
    public function claimPreviewJobForExecution(int $jobUid): bool
    {
        if ($jobUid <= 0) {
            return false;
        }

        $now = time();
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::JOB_TABLE);
        $affectedRows = (int)$queryBuilder
            ->update(self::JOB_TABLE)
            ->set('status', 'running')
            ->set('started_at', $now)
            ->set('lease_token', bin2hex(random_bytes(16)))
            ->set('lease_renewed_at', $now)
            ->set('tstamp', $now)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($jobUid, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                $queryBuilder->expr()->in('status', $queryBuilder->createNamedParameter(['previewed', 'interrupted'], \Doctrine\DBAL\ArrayParameterType::STRING))
            )
            ->executeStatement();

        return $affectedRows === 1;
    }

    /**
     * Pause a chunked run: more items remain, so the job goes back to the resumable `interrupted`
     * state (kept lease-fresh) for the next execute run/scheduler tick to pick up.
     */
    public function pauseForResume(int $jobUid): void
    {
        $this->updateJobStatus($jobUid, 'interrupted', ['lease_renewed_at' => time()]);
    }

    /**
     * Number of job items that have not been handled yet (processed_at still 0).
     */
    public function countUnprocessedItems(int $jobUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::ITEM_TABLE);

        return (int)$queryBuilder
            ->count('uid')
            ->from(self::ITEM_TABLE)
            ->where(
                $queryBuilder->expr()->eq('job_uid', $queryBuilder->createNamedParameter($jobUid, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('processed_at', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT))
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Recompute the job's persisted counters from the actual item statuses (single source of truth),
     * so the totals are correct whether the job ran in one pass, was resumed, or ran in chunks.
     *
     * @return array{processed_items: int, translated_items: int, blocked_items: int, skipped_items: int, failed_items: int}
     */
    public function countExecutedItems(int $jobUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::ITEM_TABLE);
        $rows = $queryBuilder
            ->select('status')
            ->addSelectLiteral($queryBuilder->expr()->count('uid', 'cnt'))
            ->from(self::ITEM_TABLE)
            ->where(
                $queryBuilder->expr()->eq('job_uid', $queryBuilder->createNamedParameter($jobUid, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT))
            )
            ->groupBy('status')
            ->executeQuery()
            ->fetchAllAssociative();

        $byStatus = [];
        foreach ($rows as $row) {
            $byStatus[(string)$row['status']] = (int)$row['cnt'];
        }

        return [
            'processed_items' => ($byStatus['translated'] ?? 0) + ($byStatus['localized'] ?? 0),
            'translated_items' => $byStatus['translated'] ?? 0,
            'blocked_items' => $byStatus['blocked'] ?? 0,
            'skipped_items' => $byStatus['skipped'] ?? 0,
            'failed_items' => $byStatus['failed'] ?? 0,
        ];
    }

    public function markFinished(int $jobUid, array $counters): void
    {
        $this->updateJobStatus($jobUid, 'finished', array_merge($counters, ['finished_at' => time()]));
    }

    public function markItemProcessed(int $itemUid, string $status, string $errorMessage = '', int $targetUid = 0, string $errorCode = ''): void
    {
        $fields = [
            'status' => $status,
            'error_message' => $errorMessage,
            'error_code' => $errorCode,
            'processed_at' => time(),
            'tstamp' => time(),
        ];
        if ($targetUid > 0) {
            $fields['target_uid'] = $targetUid;
        }

        $this->connectionPool->getConnectionForTable(self::ITEM_TABLE)->update(self::ITEM_TABLE, $fields, ['uid' => $itemUid]);
    }

    /**
     * @return array{jobs: int, items: int}
     */
    public function cleanupFinishedJobs(int $olderThanTimestamp, bool $dryRun = true): array
    {
        $jobConnection = $this->connectionPool->getConnectionForTable(self::JOB_TABLE);
        $queryBuilder = $jobConnection->createQueryBuilder();
        $jobUids = array_map('intval', $queryBuilder
            ->select('uid')
            ->from(self::JOB_TABLE)
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                $queryBuilder->expr()->in('status', $queryBuilder->createNamedParameter(['finished', 'discarded', 'preview_failed', 'interrupted'], \Doctrine\DBAL\ArrayParameterType::STRING)),
                $queryBuilder->expr()->lt('tstamp', $queryBuilder->createNamedParameter($olderThanTimestamp, \PDO::PARAM_INT))
            )
            ->executeQuery()
            ->fetchFirstColumn());

        if ($jobUids === []) {
            return ['jobs' => 0, 'items' => 0];
        }

        $itemConnection = $this->connectionPool->getConnectionForTable(self::ITEM_TABLE);
        $itemQueryBuilder = $itemConnection->createQueryBuilder();
        $itemCount = (int)$itemQueryBuilder
            ->count('uid')
            ->from(self::ITEM_TABLE)
            ->where(
                $itemQueryBuilder->expr()->eq('deleted', $itemQueryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                $itemQueryBuilder->expr()->in('job_uid', $itemQueryBuilder->createNamedParameter($jobUids, \Doctrine\DBAL\ArrayParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchOne();

        if (!$dryRun) {
            $now = time();
            $jobUpdate = $jobConnection->createQueryBuilder();
            $jobUpdate
                ->update(self::JOB_TABLE)
                ->set('deleted', '1')
                ->set('tstamp', (string)$now)
                ->where($jobUpdate->expr()->in('uid', $jobUpdate->createNamedParameter($jobUids, \Doctrine\DBAL\ArrayParameterType::INTEGER)))
                ->executeStatement();

            $itemUpdate = $itemConnection->createQueryBuilder();
            $itemUpdate
                ->update(self::ITEM_TABLE)
                ->set('deleted', '1')
                ->set('tstamp', (string)$now)
                ->where($itemUpdate->expr()->in('job_uid', $itemUpdate->createNamedParameter($jobUids, \Doctrine\DBAL\ArrayParameterType::INTEGER)))
                ->executeStatement();
        }

        return ['jobs' => count($jobUids), 'items' => $itemCount];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function selectionFromArray(array $data): BatchSelection
    {
        $pages = [];
        foreach (is_array($data['selectedPages'] ?? null) ? $data['selectedPages'] : [] as $pageData) {
            if (is_array($pageData)) {
                $pages[] = new PageSelection(
                    (int)($pageData['pageUid'] ?? 0),
                    (bool)($pageData['includePageRecord'] ?? true),
                    (bool)($pageData['includeElements'] ?? true)
                );
            }
        }

        $subtrees = [];
        foreach (is_array($data['selectedSubtrees'] ?? null) ? $data['selectedSubtrees'] : [] as $subtreeData) {
            if (is_array($subtreeData)) {
                $subtrees[] = new SubtreeSelection(
                    (int)($subtreeData['rootPageUid'] ?? 0),
                    (bool)($subtreeData['includeRoot'] ?? true),
                    (bool)($subtreeData['includeHidden'] ?? false),
                    (bool)($subtreeData['includeElements'] ?? true)
                );
            }
        }

        $elements = [];
        foreach (is_array($data['selectedElements'] ?? null) ? $data['selectedElements'] : [] as $elementData) {
            if (is_array($elementData)) {
                $elements[] = new ElementSelection((int)($elementData['contentUid'] ?? 0), (int)($elementData['sourcePageUid'] ?? 0));
            }
        }

        return new BatchSelection(
            (string)($data['siteIdentifier'] ?? ''),
            (int)($data['sourceLanguageId'] ?? 0),
            (int)($data['targetLanguageId'] ?? 0),
            TranslationMode::fromRequestValue((string)($data['mode'] ?? '')),
            array_values(array_filter($pages, static fn(PageSelection $selection): bool => $selection->pageUid > 0)),
            array_values(array_filter($subtrees, static fn(SubtreeSelection $selection): bool => $selection->rootPageUid > 0)),
            array_values(array_filter($elements, static fn(ElementSelection $selection): bool => $selection->contentUid > 0)),
            array_values(array_map('intval', is_array($data['excludedPageUids'] ?? null) ? $data['excludedPageUids'] : [])),
            array_values(array_map('intval', is_array($data['excludedElementUids'] ?? null) ? $data['excludedElementUids'] : [])),
            isset($data['glossaryId']) && $data['glossaryId'] !== '' ? (string)$data['glossaryId'] : null,
            (string)($data['styleRuleId'] ?? ''),
            (string)($data['customInstructions'] ?? '')
        );
    }

    private function errorCodeForItem(PreflightItem $item): string
    {
        foreach ($item->errors as $error) {
            if (str_contains($error, 'source language record')) {
                return 'source_missing';
            }
        }

        return $item->permission->allowed ? 'preflight_error' : 'permission_denied';
    }
}
