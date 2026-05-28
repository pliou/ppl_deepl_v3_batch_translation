<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service;

use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\FieldOperation;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Site\SiteFinder;

final class BatchResultViewModelService
{
    public function __construct(
        private readonly BatchJobLogger $jobLogger,
        private readonly SiteFinder $siteFinder,
        private readonly UriBuilder $uriBuilder
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(int $jobUid): array
    {
        $stored = $this->jobLogger->loadJob($jobUid);
        if ($stored === null) {
            return [
                'jobUid' => 0,
                'status' => '',
                'counts' => [],
                'rows' => [],
                'isEmpty' => true,
            ];
        }

        $job = $stored['job'];
        $rows = [];
        $counts = [
            'createdRecords' => 0,
            'updatedRecords' => 0,
            'writtenFields' => 0,
            'skippedRecords' => 0,
            'blockedRecords' => 0,
            'failedRecords' => 0,
            'warnings' => 0,
        ];

        foreach ($stored['items'] as $storedItem) {
            $item = $this->decodeItem((string)($storedItem['options_json'] ?? ''));
            if ($item === []) {
                continue;
            }
            $status = (string)($storedItem['status'] ?? '');
            $targetUid = (int)($storedItem['target_uid'] ?? 0);
            if ($targetUid <= 0) {
                $targetUid = (int)($item['targetUid'] ?? 0);
            }

            $operations = $this->operations($item);
            $writtenFields = $this->writtenFields($operations, $status);
            $recordAction = (string)($item['recordAction'] ?? '');
            if ($recordAction === 'create' && $targetUid > 0 && !in_array($status, ['blocked', 'failed', 'skipped'], true)) {
                $counts['createdRecords']++;
            } elseif ($status === 'translated') {
                $counts['updatedRecords']++;
            }
            if ($status === 'skipped') {
                $counts['skippedRecords']++;
            } elseif ($status === 'blocked') {
                $counts['blockedRecords']++;
            } elseif ($status === 'failed') {
                $counts['failedRecords']++;
            }
            if ((string)($storedItem['error_message'] ?? '') !== '') {
                $counts['warnings']++;
            }
            $counts['writtenFields'] += count($writtenFields);

            $table = (string)($item['table'] ?? $storedItem['source_table'] ?? '');
            $sourceUid = (int)($item['sourceUid'] ?? $storedItem['source_uid'] ?? 0);
            $rows[] = [
                'type' => (string)($item['itemType'] ?? $storedItem['item_type'] ?? ''),
                'table' => $table,
                'sourceUid' => $sourceUid,
                'targetUid' => $targetUid,
                'label' => (string)($item['label'] ?? ($table . ' #' . $sourceUid)),
                'status' => $status,
                'recordAction' => $recordAction,
                'writtenFields' => implode(', ', $writtenFields),
                'error' => (string)($storedItem['error_message'] ?? ''),
                'frontendUrl' => $table === 'pages' && $targetUid > 0
                    ? $this->frontendUrl((string)($job['site_identifier'] ?? ''), $sourceUid, (int)($job['target_language_id'] ?? 0))
                    : '',
                'backendUrl' => $targetUid > 0 ? $this->backendEditUrl($table, $targetUid) : '',
            ];
        }

        return [
            'jobUid' => (int)$job['uid'],
            'status' => (string)($job['status'] ?? ''),
            'counts' => $counts,
            'rows' => $rows,
            'isEmpty' => $rows === [],
        ];
    }

    public function buildCsv(int $jobUid): string
    {
        $result = $this->build($jobUid);
        $handle = fopen('php://temp', 'r+');
        if (!is_resource($handle)) {
            return '';
        }

        fputcsv($handle, ['type', 'table', 'source_uid', 'target_uid', 'status', 'record_action', 'written_fields', 'error', 'frontend_url', 'backend_url']);
        foreach ($result['rows'] as $row) {
            fputcsv($handle, [
                $row['type'],
                $row['table'],
                $row['sourceUid'],
                $row['targetUid'],
                $row['status'],
                $row['recordAction'],
                $row['writtenFields'],
                $row['error'],
                $row['frontendUrl'],
                $row['backendUrl'],
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeItem(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $item
     * @return FieldOperation[]
     */
    private function operations(array $item): array
    {
        $operations = [];
        foreach (is_array($item['fieldOperations'] ?? null) ? $item['fieldOperations'] : [] as $operationData) {
            if (is_array($operationData)) {
                $operations[] = FieldOperation::fromArray($operationData);
            }
        }

        return $operations;
    }

    /**
     * @param FieldOperation[] $operations
     * @return string[]
     */
    private function writtenFields(array $operations, string $status): array
    {
        if (!in_array($status, ['translated', 'localized'], true)) {
            return [];
        }

        $fields = [];
        foreach ($operations as $operation) {
            if (trim($operation->translatedValue) !== '') {
                $fields[] = $operation->field;
            }
        }

        return array_values(array_unique($fields));
    }

    private function frontendUrl(string $siteIdentifier, int $pageUid, int $targetLanguageId): string
    {
        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
            return (string)$site->getRouter()->generateUri($pageUid, ['_language' => $targetLanguageId]);
        } catch (\Throwable) {
            return '';
        }
    }

    private function backendEditUrl(string $table, int $targetUid): string
    {
        if ($table === '' || $targetUid <= 0) {
            return '';
        }

        try {
            return (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                'edit' => [
                    $table => [
                        $targetUid => 'edit',
                    ],
                ],
            ]);
        } catch (\Throwable) {
            return '';
        }
    }
}
