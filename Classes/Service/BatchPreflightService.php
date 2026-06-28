<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service;

use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\BatchSelection;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\FieldDefinition;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\FieldOperation;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\PreflightItem;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\PreflightPlan;
use Ppl\PplDeeplV3BatchTranslation\Domain\Enum\TranslationMode;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class BatchPreflightService
{
    use PageScanLimitTrait;

    private const PAGE_SCAN_LIMIT = 3000;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly RecordLocalizationService $localizationService,
        private readonly TranslationFieldDefinitionService $fieldDefinitionService,
        private readonly BatchRecordMappingService $recordMappingService,
        private readonly BatchPermissionService $permissionService
    ) {}

    public function buildPlan(BatchSelection $selection): PreflightPlan
    {
        $messages = [];
        if ($selection->isEmpty()) {
            $messages[] = ['type' => 'warning', 'text' => 'Select at least one page, subtree or content element.'];
        }
        if ($selection->targetLanguageId === $selection->sourceLanguageId) {
            $messages[] = ['type' => 'error', 'text' => 'Target language must not be the source language.'];
        }
        $records = $this->resolveSelectedRecords($selection);
        $items = [];
        foreach ($records as $record) {
            $items[] = $this->buildItem($selection, $record);
        }

        return new PreflightPlan($selection, $items, $messages);
    }

    /**
     * @return array<int, array{table: string, baseUid: int, sourceUid: int, sourcePageUid: int, itemType: string, row: array<string, mixed>, baseRow: array<string, mixed>, sourceMissing: bool}>
     */
    private function resolveSelectedRecords(BatchSelection $selection): array
    {
        $records = [];
        $seen = [];

        foreach ($selection->selectedPages as $pageSelection) {
            if ($selection->hasExcludedPage($pageSelection->pageUid)) {
                continue;
            }
            $page = $this->fetchRecord('pages', $pageSelection->pageUid);
            if ($page !== null) {
                $this->appendMappedRecord($records, $seen, $selection, 'pages', $pageSelection->pageUid, $pageSelection->pageUid, 'page');
                if ($pageSelection->includeElements) {
                    foreach ($this->fetchPageContentElements($pageSelection->pageUid) as $element) {
                        if ($selection->hasExcludedElement((int)$element['uid'])) {
                            continue;
                        }
                        $this->appendMappedRecord($records, $seen, $selection, 'tt_content', (int)$element['uid'], (int)$element['pid'], 'element');
                    }
                }
            }
        }

        foreach ($selection->selectedSubtrees as $subtreeSelection) {
            foreach ($this->fetchSubtreePages($subtreeSelection->rootPageUid, $subtreeSelection->includeRoot, $subtreeSelection->includeHidden) as $page) {
                $pageUid = (int)$page['uid'];
                if ($selection->hasExcludedPage($pageUid)) {
                    continue;
                }
                $this->appendMappedRecord($records, $seen, $selection, 'pages', $pageUid, $pageUid, 'page');
                if ($subtreeSelection->includeElements) {
                    foreach ($this->fetchPageContentElements($pageUid) as $element) {
                        if ($selection->hasExcludedElement((int)$element['uid'])) {
                            continue;
                        }
                        $this->appendMappedRecord($records, $seen, $selection, 'tt_content', (int)$element['uid'], (int)$element['pid'], 'element');
                    }
                }
            }
        }

        foreach ($selection->selectedElements as $elementSelection) {
            if ($selection->hasExcludedElement($elementSelection->contentUid)) {
                continue;
            }
            $element = $this->fetchRecord('tt_content', $elementSelection->contentUid);
            if ($element !== null) {
                if ($selection->hasExcludedPage((int)$element['pid'])) {
                    continue;
                }
                $this->appendMappedRecord($records, $seen, $selection, 'tt_content', $elementSelection->contentUid, (int)$element['pid'], 'element');
            }
        }

        return $records;
    }

    /**
     * @param array<int, array{table: string, baseUid: int, sourceUid: int, sourcePageUid: int, itemType: string, row: array<string, mixed>, baseRow: array<string, mixed>, sourceMissing: bool}> $records
     * @param array<string, bool> $seen
     */
    private function appendMappedRecord(array &$records, array &$seen, BatchSelection $selection, string $table, int $baseUid, int $sourcePageUid, string $itemType): void
    {
        $key = $table . ':' . $baseUid;
        if (isset($seen[$key])) {
            return;
        }

        $mapping = $this->recordMappingService->resolveSourceRecord($table, $baseUid, $selection->sourceLanguageId);
        if (!is_array($mapping['base'])) {
            return;
        }

        $seen[$key] = true;
        $records[] = [
            'table' => $table,
            'baseUid' => $baseUid,
            'sourceUid' => (int)$mapping['sourceUid'],
            'sourcePageUid' => $sourcePageUid,
            'itemType' => $itemType,
            'row' => is_array($mapping['source']) ? $mapping['source'] : $mapping['base'],
            'baseRow' => $mapping['base'],
            'sourceMissing' => (bool)$mapping['sourceMissing'],
        ];
    }

    /**
     * @param array{table: string, baseUid: int, sourceUid: int, sourcePageUid: int, itemType: string, row: array<string, mixed>, baseRow: array<string, mixed>, sourceMissing: bool} $record
     */
    private function buildItem(BatchSelection $selection, array $record): PreflightItem
    {
        $table = $record['table'];
        $baseUid = $record['baseUid'];
        $sourceUid = $record['sourceUid'];
        $targetUid = $this->localizationService->findLocalizedRecordUid($table, $baseUid, $selection->targetLanguageId);
        $targetRecord = $targetUid > 0 ? $this->fetchRecord($table, $targetUid) : null;
        $permission = $this->permissionService->checkRecordAccess($table, $record['baseRow'], $record['sourcePageUid'], $selection->targetLanguageId);
        $errors = $this->validateSourceRecord($record['row'], $selection, (bool)$record['sourceMissing']);
        $status = $record['sourceMissing'] ? 'source_missing' : $this->statusForRecord($table, $record['row'], $targetRecord);

        if (!$permission->allowed) {
            $status = 'blocked';
        }

        $recordAction = $this->recordAction($selection->mode, $targetUid);
        $fieldOperations = $record['sourceMissing']
            ? []
            : $this->buildFieldOperations($selection->mode, $table, $record['row'], $targetRecord, $targetUid, $record['sourcePageUid'], $baseUid);

        return new PreflightItem(
            $table . ':' . $baseUid,
            $record['itemType'],
            $table,
            $sourceUid,
            $targetUid,
            $record['sourcePageUid'],
            $this->labelForRecord($table, $record['row']),
            $status,
            $recordAction,
            $permission,
            $fieldOperations,
            $errors,
            $baseUid
        );
    }

    /**
     * @return string[]
     */
    private function validateSourceRecord(array $row, BatchSelection $selection, bool $sourceMissing): array
    {
        $errors = [];
        if ($sourceMissing) {
            $errors[] = 'Selected source language record is missing.';
            return $errors;
        }
        if ((int)($row['sys_language_uid'] ?? 0) !== $selection->sourceLanguageId) {
            $errors[] = 'Selected record is not in the requested source language.';
        }
        if ($selection->targetLanguageId <= 0) {
            $errors[] = 'Select a target language.';
        }

        return $errors;
    }

    private function recordAction(TranslationMode $mode, int $targetUid): string
    {
        if ($targetUid <= 0) {
            return 'create';
        }

        return match ($mode) {
            TranslationMode::CreateMissingRecordsOnly,
            TranslationMode::TranslateSelectedSkipExisting => 'skip',
            default => 'update',
        };
    }

    /**
     * @return FieldOperation[]
     */
    private function buildFieldOperations(TranslationMode $mode, string $table, array $sourceRecord, ?array $targetRecord, int $targetUid, int $sourcePageUid, int $baseUid): array
    {
        if ($mode === TranslationMode::CreateMissingRecordsOnly) {
            return [];
        }

        if ($targetUid > 0 && $mode === TranslationMode::TranslateSelectedSkipExisting) {
            return [];
        }

        $operations = [];
        foreach ($this->fieldDefinitionService->getDefinitions($table) as $definition) {
            $sourceValue = trim((string)($sourceRecord[$definition->field] ?? ''));
            if ($sourceValue === '') {
                continue;
            }

            $targetValue = trim((string)($targetRecord[$definition->field] ?? ''));
            $writeAction = $this->writeActionForField($mode, $targetUid, $sourceValue, $targetValue);
            if ($writeAction === 'skip') {
                continue;
            }

            $sourceUid = (int)$sourceRecord['uid'];
            $operations[] = new FieldOperation(
                $table . ':' . $baseUid . ':' . $definition->field,
                $table,
                $sourceUid,
                $targetUid,
                $sourcePageUid,
                $definition->field,
                $definition->label,
                $sourceValue,
                $targetValue,
                $writeAction,
                $definition->mode === 'html' ? 'html' : ''
            );
        }

        return $operations;
    }

    private function writeActionForField(TranslationMode $mode, int $targetUid, string $sourceValue, string $targetValue): string
    {
        $targetNeedsTranslation = $targetValue === '' || $this->valuesLookUntranslated($sourceValue, $targetValue);

        return match ($mode) {
            TranslationMode::RetranslateSelected => $targetUid <= 0 ? 'translate' : 'overwrite',
            TranslationMode::UpdateEmptyFieldsOnly => $targetValue === '' ? 'fill_empty' : 'skip',
            TranslationMode::TranslateSelectedSkipExisting => $targetUid <= 0 ? 'translate' : 'skip',
            TranslationMode::TranslateMissingOnly,
            TranslationMode::PreviewOnly => $targetUid <= 0 || $targetNeedsTranslation ? 'translate' : 'skip',
            TranslationMode::CreateMissingRecordsOnly => 'skip',
        };
    }

    private function statusForRecord(string $table, array $sourceRecord, ?array $targetRecord): string
    {
        if (!empty($sourceRecord['hidden'])) {
            return 'hidden';
        }

        if ($targetRecord === null) {
            return 'missing';
        }

        $missing = 0;
        $translated = 0;
        foreach ($this->fieldDefinitionService->getDefinitions($table) as $definition) {
            $sourceValue = trim((string)($sourceRecord[$definition->field] ?? ''));
            if ($sourceValue === '') {
                continue;
            }
            $targetValue = trim((string)($targetRecord[$definition->field] ?? ''));
            if ($targetValue === '' || $this->valuesLookUntranslated($sourceValue, $targetValue)) {
                $missing++;
            } else {
                $translated++;
            }
        }

        if ($missing > 0 && $translated > 0) {
            return 'partial';
        }

        if ($missing > 0) {
            return 'translated_but_empty_fields';
        }

        return 'translated';
    }

    private function valuesLookUntranslated(string $sourceValue, string $targetValue): bool
    {
        return $this->normalizeComparableValue($sourceValue) !== ''
            && $this->normalizeComparableValue($sourceValue) === $this->normalizeComparableValue($targetValue);
    }

    private function normalizeComparableValue(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', strip_tags($value)) ?? ''));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchSubtreePages(int $rootPageUid, bool $includeRoot, bool $includeHidden): array
    {
        $allPages = $this->fetchAllDefaultPages();
        $children = [];
        foreach ($allPages as $page) {
            $children[(int)$page['pid']][] = $page;
        }

        $result = [];
        $stack = [];
        if ($includeRoot && isset($allPages[$rootPageUid])) {
            $stack[] = $allPages[$rootPageUid];
        } else {
            $stack = $children[$rootPageUid] ?? [];
        }

        while ($stack !== []) {
            $page = array_shift($stack);
            if ($includeHidden || empty($page['hidden'])) {
                $result[] = $page;
            }
            foreach ($children[(int)$page['uid']] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchAllDefaultPages(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('*')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER))
            )
            ->orderBy('pid', 'ASC')
            ->addOrderBy('sorting', 'ASC')
            ->setMaxResults(self::PAGE_SCAN_LIMIT + 1)
            ->executeQuery()
            ->fetchAllAssociative();
        $rows = $this->capScannedPages($rows, self::PAGE_SCAN_LIMIT);

        $pages = [];
        foreach ($rows as $row) {
            $pages[(int)$row['uid']] = $row;
        }

        return $pages;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchPageContentElements(int $pageUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('*')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, \Doctrine\DBAL\ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER))
            )
            ->orderBy('colPos', 'ASC')
            ->addOrderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    private function fetchRecord(string $table, int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, \Doctrine\DBAL\ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    private function labelForRecord(string $table, array $row): string
    {
        if ($table === 'pages') {
            return sprintf('Page %d: %s', (int)$row['uid'], (string)($row['title'] ?? ''));
        }

        foreach (['header', 'subheader', 'bodytext'] as $field) {
            $value = trim(strip_tags((string)($row[$field] ?? '')));
            if ($value !== '') {
                return sprintf('Element %d: %s', (int)$row['uid'], mb_substr($value, 0, 80));
            }
        }

        return sprintf('Element %d: %s', (int)$row['uid'], (string)($row['CType'] ?? 'tt_content'));
    }
}
