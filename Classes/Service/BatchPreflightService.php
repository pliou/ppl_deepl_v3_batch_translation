<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service;

use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\BatchSelection;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\FieldDefinition;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\FieldOperation;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\PermissionResult;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\PreflightItem;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\PreflightPlan;
use Ppl\PplDeeplV3BatchTranslation\Domain\Enum\TranslationMode;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

final class BatchPreflightService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly RecordLocalizationService $localizationService,
        private readonly TranslationFieldDefinitionService $fieldDefinitionService
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
        if ($selection->sourceLanguageId !== 0) {
            $messages[] = ['type' => 'warning', 'text' => 'P0 expects default-language source records. Non-default source language is not executed.'];
        }

        $records = $this->resolveSelectedRecords($selection);
        $items = [];
        foreach ($records as $record) {
            $items[] = $this->buildItem($selection, $record);
        }

        return new PreflightPlan($selection, $items, $messages);
    }

    /**
     * @return array<int, array{table: string, uid: int, sourcePageUid: int, itemType: string, row: array<string, mixed>}>
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
                $this->appendRecord($records, $seen, 'pages', $pageSelection->pageUid, $pageSelection->pageUid, 'page', $page);
                if ($pageSelection->includeElements) {
                    foreach ($this->fetchPageContentElements($pageSelection->pageUid) as $element) {
                        if ($selection->hasExcludedElement((int)$element['uid'])) {
                            continue;
                        }
                        $this->appendRecord($records, $seen, 'tt_content', (int)$element['uid'], (int)$element['pid'], 'element', $element);
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
                $this->appendRecord($records, $seen, 'pages', $pageUid, $pageUid, 'page', $page);
                if ($subtreeSelection->includeElements) {
                    foreach ($this->fetchPageContentElements($pageUid) as $element) {
                        if ($selection->hasExcludedElement((int)$element['uid'])) {
                            continue;
                        }
                        $this->appendRecord($records, $seen, 'tt_content', (int)$element['uid'], (int)$element['pid'], 'element', $element);
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
                $this->appendRecord($records, $seen, 'tt_content', $elementSelection->contentUid, (int)$element['pid'], 'element', $element);
            }
        }

        return $records;
    }

    /**
     * @param array<int, array{table: string, uid: int, sourcePageUid: int, itemType: string, row: array<string, mixed>}> $records
     * @param array<string, bool> $seen
     * @param array<string, mixed> $row
     */
    private function appendRecord(array &$records, array &$seen, string $table, int $uid, int $sourcePageUid, string $itemType, array $row): void
    {
        $key = $table . ':' . $uid;
        if (isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $records[] = [
            'table' => $table,
            'uid' => $uid,
            'sourcePageUid' => $sourcePageUid,
            'itemType' => $itemType,
            'row' => $row,
        ];
    }

    /**
     * @param array{table: string, uid: int, sourcePageUid: int, itemType: string, row: array<string, mixed>} $record
     */
    private function buildItem(BatchSelection $selection, array $record): PreflightItem
    {
        $table = $record['table'];
        $sourceUid = $record['uid'];
        $targetUid = $this->localizationService->findLocalizedRecordUid($table, $sourceUid, $selection->targetLanguageId);
        $targetRecord = $targetUid > 0 ? $this->fetchRecord($table, $targetUid) : null;
        $permission = $this->checkPermissions($table, $record['row'], $record['sourcePageUid'], $selection->targetLanguageId);
        $errors = $this->validateSourceRecord($record['row'], $selection);
        $status = $this->statusForRecord($table, $record['row'], $targetRecord);

        if (!$permission->allowed) {
            $status = 'blocked';
        }

        $recordAction = $this->recordAction($selection->mode, $targetUid);
        $fieldOperations = $this->buildFieldOperations($selection->mode, $table, $record['row'], $targetRecord, $targetUid, $record['sourcePageUid']);

        return new PreflightItem(
            $table . ':' . $sourceUid,
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
            $errors
        );
    }

    /**
     * @return string[]
     */
    private function validateSourceRecord(array $row, BatchSelection $selection): array
    {
        $errors = [];
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
    private function buildFieldOperations(TranslationMode $mode, string $table, array $sourceRecord, ?array $targetRecord, int $targetUid, int $sourcePageUid): array
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
                $table . ':' . $sourceUid . ':' . $definition->field,
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

    private function checkPermissions(string $table, array $row, int $sourcePageUid, int $targetLanguageId): PermissionResult
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return PermissionResult::blocked('No backend user is available.');
        }

        if ($this->isAdmin($backendUser)) {
            return PermissionResult::allowed();
        }

        $reasons = [];
        if (!$this->hasTableModifyPermission($backendUser, $table)) {
            $reasons[] = sprintf('Missing %s modify permission.', $table);
        }
        if (!$this->hasLanguageAccess($backendUser, $targetLanguageId)) {
            $reasons[] = sprintf('User cannot access target language %d.', $targetLanguageId);
        }

        $page = $table === 'pages' ? $row : $this->fetchRecord('pages', $sourcePageUid);
        if ($page === null) {
            $reasons[] = sprintf('Source page %d was not found.', $sourcePageUid);
        } else {
            $permission = $table === 'tt_content' ? Permission::CONTENT_EDIT : Permission::PAGE_EDIT;
            if (!$this->hasPagePermission($backendUser, $page, $permission)) {
                $reasons[] = $table === 'tt_content'
                    ? 'Missing content edit permission on source page.'
                    : 'Missing page edit permission.';
            }
        }

        return $reasons === [] ? PermissionResult::allowed() : PermissionResult::blocked(...$reasons);
    }

    private function isAdmin(BackendUserAuthentication $backendUser): bool
    {
        return !empty($backendUser->user['admin']);
    }

    private function hasTableModifyPermission(BackendUserAuthentication $backendUser, string $table): bool
    {
        return !method_exists($backendUser, 'check') || $backendUser->check('tables_modify', $table);
    }

    private function hasLanguageAccess(BackendUserAuthentication $backendUser, int $languageId): bool
    {
        return !method_exists($backendUser, 'checkLanguageAccess') || $backendUser->checkLanguageAccess($languageId);
    }

    private function hasPagePermission(BackendUserAuthentication $backendUser, array $page, int $permission): bool
    {
        return !method_exists($backendUser, 'doesUserHaveAccess') || $backendUser->doesUserHaveAccess($page, $permission);
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
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT))
            )
            ->orderBy('pid', 'ASC')
            ->addOrderBy('sorting', 'ASC')
            ->setMaxResults(3000)
            ->executeQuery()
            ->fetchAllAssociative();

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
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT))
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
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT))
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
