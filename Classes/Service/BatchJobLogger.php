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
                'target_uid' => $item->targetUid,
                'source_page_uid' => $item->sourcePageUid,
                'status' => $item->isBlocked() ? 'blocked' : $item->recordAction,
                'error_message' => implode('; ', array_merge($item->permission->reasons, $item->errors)),
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
        $this->updateJobStatus($jobUid, 'running', ['started_at' => time()]);
    }

    public function markFinished(int $jobUid, array $counters): void
    {
        $this->updateJobStatus($jobUid, 'finished', array_merge($counters, ['finished_at' => time()]));
    }

    public function markItemProcessed(int $itemUid, string $status, string $errorMessage = '', int $targetUid = 0): void
    {
        $fields = [
            'status' => $status,
            'error_message' => $errorMessage,
            'processed_at' => time(),
            'tstamp' => time(),
        ];
        if ($targetUid > 0) {
            $fields['target_uid'] = $targetUid;
        }

        $this->connectionPool->getConnectionForTable(self::ITEM_TABLE)->update(self::ITEM_TABLE, $fields, ['uid' => $itemUid]);
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
}
