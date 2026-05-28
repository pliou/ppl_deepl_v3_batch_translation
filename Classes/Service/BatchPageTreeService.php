<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service;

use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\BatchSelection;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

final class BatchPageTreeService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly RecordLocalizationService $localizationService,
        private readonly TranslationFieldDefinitionService $fieldDefinitionService
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTree(int $rootPageUid, int $targetLanguageId, BatchSelection $selection, string $search = '', string $statusFilter = ''): array
    {
        $pages = $this->fetchDefaultLanguagePages();
        $children = [];
        foreach ($pages as $page) {
            $children[(int)$page['pid']][] = $page;
        }

        $selectedPages = array_fill_keys(array_map(static fn($item): int => $item->pageUid, $selection->selectedPages), true);
        $selectedSubtrees = array_fill_keys(array_map(static fn($item): int => $item->rootPageUid, $selection->selectedSubtrees), true);
        $excludedPages = array_fill_keys($selection->excludedPageUids, true);
        $selectedElementPages = array_fill_keys(array_map(static fn($item): int => $item->sourcePageUid, $selection->selectedElements), true);
        $outlineNumbers = $this->outlineNumbers($pages, $children, $rootPageUid);
        $rows = [];
        $startUid = $rootPageUid > 0 ? $rootPageUid : 0;

        if ($rootPageUid > 0 && isset($pages[$rootPageUid])) {
            $this->appendTreeRow($rows, $pages[$rootPageUid], $children, $targetLanguageId, $selectedPages, $selectedSubtrees, $excludedPages, $selectedElementPages, $outlineNumbers, 0, $search, $statusFilter);
        } else {
            foreach ($children[$startUid] ?? [] as $index => $page) {
                $this->appendTreeRow($rows, $page, $children, $targetLanguageId, $selectedPages, $selectedSubtrees, $excludedPages, $selectedElementPages, $outlineNumbers, 0, $search, $statusFilter);
            }
        }

        return $rows;
    }

    /**
     * @return array{label: string, rootPageId: int, rootTitle: string, pageCount: int, hint: string}
     */
    public function getSiteSummary(string $siteLabel, int $rootPageUid): array
    {
        $pages = $this->fetchDefaultLanguagePages();
        $children = [];
        foreach ($pages as $page) {
            $children[(int)$page['pid']][] = $page;
        }

        $pageCount = 0;
        $stack = [];
        if ($rootPageUid > 0 && isset($pages[$rootPageUid])) {
            $stack[] = $pages[$rootPageUid];
        } else {
            $stack = $children[0] ?? [];
        }

        while ($stack !== []) {
            $page = array_shift($stack);
            $pageCount++;
            foreach ($children[(int)$page['uid']] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return [
            'label' => $siteLabel,
            'rootPageId' => $rootPageUid,
            'rootTitle' => trim((string)($pages[$rootPageUid]['title'] ?? '')) ?: 'Root page',
            'pageCount' => $pageCount,
            'hint' => 'Only pages below the selected site root are shown.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getPageDetails(int $pageUid, int $targetLanguageId, int $rootPageUid = 0): array
    {
        if ($pageUid <= 0) {
            return [
                'page' => null,
                'pageFields' => [],
                'elements' => [],
            ];
        }

        $page = $this->fetchRecord('pages', $pageUid);
        if ($page === null) {
            return [
                'page' => null,
                'pageFields' => [],
                'elements' => [],
            ];
        }

        $targetUid = $this->localizationService->findLocalizedRecordUid('pages', $pageUid, $targetLanguageId);
        $targetPage = $targetUid > 0 ? $this->fetchRecord('pages', $targetUid) : null;
        $pageFields = [];
        $pages = $this->fetchDefaultLanguagePages();
        $children = [];
        foreach ($pages as $row) {
            $children[(int)$row['pid']][] = $row;
        }
        $outlineNumbers = $this->outlineNumbers($pages, $children, $rootPageUid);
        $elements = $this->getContentElementRows($pageUid, $targetLanguageId);
        $blockedElements = array_filter($elements, static fn(array $element): bool => (bool)($element['blocked'] ?? false));

        foreach ($this->fieldDefinitionService->getDefinitions('pages') as $definition) {
            $sourceValue = trim((string)($page[$definition->field] ?? ''));
            $targetValue = trim((string)($targetPage[$definition->field] ?? ''));
            if ($sourceValue === '' && $targetValue === '') {
                continue;
            }

            $pageFields[] = [
                'field' => $definition->field,
                'label' => $definition->label,
                'sourcePreview' => mb_substr($sourceValue, 0, 120),
                'targetPreview' => mb_substr($targetValue, 0, 120),
                'status' => $targetUid <= 0 || $targetValue === '' ? 'missing' : 'translated',
            ];
        }

        $blockReasons = $this->pageBlockReasons($page, $targetLanguageId);

        return [
            'page' => [
                'uid' => (int)$page['uid'],
                'title' => (string)($page['title'] ?? ''),
                'hierarchy' => (string)($outlineNumbers[(int)$page['uid']] ?? ''),
                'targetUid' => $targetUid,
                'targetState' => $targetUid > 0 ? 'exists' : 'missing',
                'hasCurrentTranslation' => $targetPage !== null && $this->hasCurrentValues('pages', $targetPage),
                'status' => $blockReasons !== []
                    ? 'blocked'
                    : $this->statusForPageWithElements($page, $targetPage, $elements),
                'blockReasons' => $blockReasons,
                'elementCount' => count($elements),
                'selectableElementCount' => count($elements) - count($blockedElements),
                'blockedElementCount' => count($blockedElements),
                'permissionSummary' => $blockReasons === [] ? 'Writable' : implode(' ', $blockReasons),
            ],
            'pageFields' => $pageFields,
            'elements' => $elements,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchDefaultLanguagePages(): array
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
            ->setMaxResults(1500)
            ->executeQuery()
            ->fetchAllAssociative();

        $pages = [];
        foreach ($rows as $row) {
            $pages[(int)$row['uid']] = $row;
        }

        return $pages;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<int, array<string, mixed>>> $children
     * @param array<int, bool> $selectedPages
     * @param array<int, bool> $selectedSubtrees
     * @param array<int, bool> $excludedPages
     * @param array<int, bool> $selectedElementPages
     * @param array<int, string> $outlineNumbers
     */
    private function appendTreeRow(array &$rows, array $page, array $children, int $targetLanguageId, array $selectedPages, array $selectedSubtrees, array $excludedPages, array $selectedElementPages, array $outlineNumbers, int $depth, string $search, string $statusFilter): void
    {
        $uid = (int)$page['uid'];
        $targetUid = $this->localizationService->findLocalizedRecordUid('pages', $uid, $targetLanguageId);
        $targetPage = $targetUid > 0 ? $this->fetchRecord('pages', $targetUid) : null;
        $blockReasons = $this->pageBlockReasons($page, $targetLanguageId);
        $elements = $this->getContentElementRows($uid, $targetLanguageId);
        $status = $blockReasons !== []
            ? 'blocked'
            : $this->statusForPageWithElements($page, $targetPage, $elements);
        $contentCount = count($elements);
        $targetContentCount = count(array_filter($elements, static fn(array $element): bool => (int)($element['targetUid'] ?? 0) > 0));
        $branchPageCount = $this->countBranchPages($uid, $children);
        $branchContentCount = $this->countBranchContentElements($uid, $children);
        $selected = isset($selectedPages[$uid]) || isset($selectedSubtrees[$uid]) || isset($selectedElementPages[$uid]);
        $excluded = isset($excludedPages[$uid]);
        $matchesSearch = $search === ''
            || str_contains((string)$uid, $search)
            || str_contains(mb_strtolower((string)($page['title'] ?? '')), mb_strtolower($search))
            || $this->pageContentMatchesSearch($uid, $search);
        $matchesStatus = match ($statusFilter) {
            '', 'all' => true,
            'selected' => $selected,
            'has_content' => $contentCount > 0,
            default => $statusFilter === $status,
        };

        if ($matchesSearch && $matchesStatus) {
            $rows[] = [
                'uid' => $uid,
                'pid' => (int)$page['pid'],
                'title' => (string)($page['title'] ?? ''),
                'hierarchy' => (string)($outlineNumbers[$uid] ?? ''),
                'depth' => $depth,
                'status' => $status,
                'hidden' => !empty($page['hidden']),
                'doktype' => (int)($page['doktype'] ?? 0),
                'targetUid' => $targetUid,
                'contentCount' => $contentCount,
                'targetContentCount' => $targetContentCount,
                'childrenCount' => count($children[$uid] ?? []),
                'branchPageCount' => $branchPageCount,
                'branchContentCount' => $branchContentCount,
                'selectedPage' => isset($selectedPages[$uid]),
                'selectedSubtree' => isset($selectedSubtrees[$uid]),
                'selected' => $selected,
                'excluded' => $excluded,
                'blockReasons' => $blockReasons,
            ];
        }

        foreach ($children[$uid] ?? [] as $child) {
            $this->appendTreeRow($rows, $child, $children, $targetLanguageId, $selectedPages, $selectedSubtrees, $excludedPages, $selectedElementPages, $outlineNumbers, $depth + 1, $search, $statusFilter);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getContentElementRows(int $pageUid, int $targetLanguageId): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $elements = $queryBuilder
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

        $rows = [];
        foreach ($elements as $index => $element) {
            $targetUid = $this->localizationService->findLocalizedRecordUid('tt_content', (int)$element['uid'], $targetLanguageId);
            $target = $targetUid > 0 ? $this->fetchRecord('tt_content', $targetUid) : null;
            $blockReasons = $this->contentBlockReasons($element, $targetLanguageId);
            $title = $this->contentTitle($element);
            $preview = $this->contentPreview($element);
            $rows[] = [
                'uid' => (int)$element['uid'],
                'pid' => (int)$element['pid'],
                'hierarchy' => 'e' . ($index + 1),
                'ctype' => (string)($element['CType'] ?? ''),
                'colPos' => (int)($element['colPos'] ?? 0),
                'title' => mb_substr($title, 0, 50),
                'fullTitle' => $title,
                'preview' => $blockReasons === [] ? $preview : '',
                'targetUid' => $targetUid,
                'targetState' => $targetUid > 0 ? 'exists' : 'missing',
                'hasCurrentTranslation' => $target !== null && $this->hasCurrentValues('tt_content', $target),
                'currentOperations' => $this->currentOperationsForRecord('tt_content', $element, $target),
                'status' => $blockReasons !== [] ? 'blocked' : $this->statusForRecord('tt_content', $element, $target),
                'fieldCount' => count($this->fieldDefinitionService->getDefinitions('tt_content')),
                'blocked' => $blockReasons !== [],
                'selectable' => $blockReasons === [],
                'blockReasons' => $blockReasons,
                'permissionSummary' => $blockReasons === [] ? 'Writable' : implode(' ', $blockReasons),
            ];
        }

        return $rows;
    }

    private function hasCurrentValues(string $table, array $targetRecord): bool
    {
        foreach ($this->fieldDefinitionService->getDefinitions($table) as $definition) {
            if (trim((string)($targetRecord[$definition->field] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function currentOperationsForRecord(string $table, array $sourceRecord, ?array $targetRecord): array
    {
        if ($targetRecord === null) {
            return [];
        }

        $operations = [];
        foreach ($this->fieldDefinitionService->getDefinitions($table) as $definition) {
            $sourceValue = $this->previewValue((string)($sourceRecord[$definition->field] ?? ''));
            $targetValue = $this->previewValue((string)($targetRecord[$definition->field] ?? ''));
            if ($sourceValue === '' && $targetValue === '') {
                continue;
            }

            $looksUntranslated = $targetValue !== '' && $this->valuesLookUntranslated($sourceValue, $targetValue);
            $operations[] = [
                'label' => $definition->label,
                'field' => $definition->field,
                'sourceValue' => $sourceValue,
                'targetValue' => $targetValue,
                'translatedValue' => '',
                'writeAction' => $targetValue === '' || $looksUntranslated ? 'missing_current' : 'existing_current',
                'actionLabel' => $targetValue === '' || $looksUntranslated ? 'not translated' : 'existing',
                'hasCurrent' => $targetValue !== '',
                'hasProposal' => false,
            ];
        }

        return $operations;
    }

    /**
     * @param array<int, array<string, mixed>> $elements
     */
    private function statusForPageWithElements(array $sourcePage, ?array $targetPage, array $elements): string
    {
        $pageStatus = $this->statusForRecord('pages', $sourcePage, $targetPage);
        if ($pageStatus === 'hidden') {
            return $pageStatus;
        }

        $hasComplete = $pageStatus === 'translated';
        $hasIncomplete = $pageStatus !== 'translated';

        foreach ($elements as $element) {
            $elementStatus = (string)($element['status'] ?? 'missing');
            if ($elementStatus === 'translated') {
                $hasComplete = true;
                continue;
            }
            $hasIncomplete = true;
        }

        if ($hasComplete && $hasIncomplete) {
            return 'partial';
        }

        if (!$hasComplete && $pageStatus === 'missing' && $this->pageHasLocalizedContent($elements)) {
            return 'partial';
        }

        return $pageStatus;
    }

    /**
     * @param array<int, array<string, mixed>> $elements
     */
    private function pageHasLocalizedContent(array $elements): bool
    {
        foreach ($elements as $element) {
            if ((int)($element['targetUid'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    private function statusForRecord(string $table, array $source, ?array $target): string
    {
        if (!empty($source['hidden'])) {
            return 'hidden';
        }

        if ($target === null) {
            return 'missing';
        }

        $hasMissing = false;
        $hasTranslated = false;
        foreach ($this->fieldDefinitionService->getDefinitions($table) as $definition) {
            $sourceValue = trim((string)($source[$definition->field] ?? ''));
            if ($sourceValue === '') {
                continue;
            }
            $targetValue = trim((string)($target[$definition->field] ?? ''));
            if ($targetValue === '' || $this->valuesLookUntranslated($sourceValue, $targetValue)) {
                $hasMissing = true;
            } else {
                $hasTranslated = true;
            }
        }

        if ($hasMissing && $hasTranslated) {
            return 'partial';
        }

        if ($hasMissing) {
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

    private function countContentElements(int $pageUid, int $languageId): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->count('uid')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId, \PDO::PARAM_INT))
            )
            ->executeQuery()
            ->fetchOne();
    }

    private function countLocalizedContentElements(int $pageUid, int $targetLanguageId): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $translationPointerField = $this->localizationService->translationPointerField('tt_content');

        return (int)$queryBuilder
            ->count('target.uid')
            ->from('tt_content', 'source')
            ->innerJoin(
                'source',
                'tt_content',
                'target',
                'target.' . $translationPointerField . ' = source.uid AND target.sys_language_uid = ' . (int)$targetLanguageId . ' AND target.deleted = 0'
            )
            ->where(
                $queryBuilder->expr()->eq('source.deleted', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('source.pid', $queryBuilder->createNamedParameter($pageUid, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('source.sys_language_uid', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT))
            )
            ->executeQuery()
            ->fetchOne();
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

    private function contentTitle(array $element): string
    {
        foreach (['header', 'subheader', 'bodytext'] as $field) {
            $value = trim(strip_tags((string)($element[$field] ?? '')));
            if ($value !== '') {
                return mb_substr($value, 0, 80);
            }
        }

        return (string)($element['CType'] ?? 'Content element');
    }

    private function contentPreview(array $element): string
    {
        foreach (['bodytext', 'subheader', 'header'] as $field) {
            $value = trim(preg_replace('/\s+/', ' ', strip_tags((string)($element[$field] ?? ''))) ?? '');
            if ($value !== '') {
                return mb_substr($value, 0, 150);
            }
        }

        return '';
    }

    private function previewValue(string $value): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($value)) ?? ''), 0, 180);
    }

    /**
     * @return string[]
     */
    private function pageBlockReasons(array $page, int $targetLanguageId): array
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication || !empty($backendUser->user['admin'])) {
            return [];
        }

        $reasons = [];
        if (method_exists($backendUser, 'check') && !$backendUser->check('tables_modify', 'pages')) {
            $reasons[] = 'Missing pages modify permission.';
        }
        if (method_exists($backendUser, 'checkLanguageAccess') && !$backendUser->checkLanguageAccess($targetLanguageId)) {
            $reasons[] = sprintf('User cannot access target language %d.', $targetLanguageId);
        }
        if (method_exists($backendUser, 'doesUserHaveAccess')
            && !$backendUser->doesUserHaveAccess($page, Permission::PAGE_EDIT)
        ) {
            $reasons[] = 'Missing page edit permission.';
        }

        return $reasons;
    }

    /**
     * @return string[]
     */
    private function contentBlockReasons(array $element, int $targetLanguageId): array
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication || !empty($backendUser->user['admin'])) {
            return [];
        }

        $reasons = [];
        if (method_exists($backendUser, 'check') && !$backendUser->check('tables_modify', 'tt_content')) {
            $reasons[] = 'Missing tt_content modify permission.';
        }
        if (method_exists($backendUser, 'checkLanguageAccess') && !$backendUser->checkLanguageAccess($targetLanguageId)) {
            $reasons[] = sprintf('User cannot access target language %d.', $targetLanguageId);
        }
        $page = $this->fetchRecord('pages', (int)($element['pid'] ?? 0));
        if ($page !== null
            && method_exists($backendUser, 'doesUserHaveAccess')
            && !$backendUser->doesUserHaveAccess($page, Permission::CONTENT_EDIT)
        ) {
            $reasons[] = 'Missing content edit permission on parent page.';
        }

        return $reasons;
    }

    /**
     * @param array<int, array<string, mixed>> $pages
     * @param array<int, array<int, array<string, mixed>>> $children
     * @return array<int, string>
     */
    private function outlineNumbers(array $pages, array $children, int $rootPageUid): array
    {
        $numbers = [];
        $roots = $rootPageUid > 0 && isset($pages[$rootPageUid])
            ? [$pages[$rootPageUid]]
            : ($children[0] ?? []);
        $this->assignOutlineNumbers($numbers, $children, $roots, '');

        return $numbers;
    }

    /**
     * @param array<int, string> $numbers
     * @param array<int, array<int, array<string, mixed>>> $children
     * @param array<int, array<string, mixed>> $pages
     */
    private function assignOutlineNumbers(array &$numbers, array $children, array $pages, string $prefix): void
    {
        $position = 1;
        foreach ($pages as $page) {
            $uid = (int)$page['uid'];
            $number = $prefix === '' ? (string)$position : $prefix . '.' . $position;
            $numbers[$uid] = $number;
            $this->assignOutlineNumbers($numbers, $children, $children[$uid] ?? [], $number);
            $position++;
        }
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $children
     */
    private function countBranchPages(int $pageUid, array $children): int
    {
        $count = 1;
        foreach ($children[$pageUid] ?? [] as $child) {
            $count += $this->countBranchPages((int)$child['uid'], $children);
        }

        return $count;
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $children
     */
    private function countBranchContentElements(int $pageUid, array $children): int
    {
        $count = $this->countContentElements($pageUid, 0);
        foreach ($children[$pageUid] ?? [] as $child) {
            $count += $this->countBranchContentElements((int)$child['uid'], $children);
        }

        return $count;
    }

    private function pageContentMatchesSearch(int $pageUid, string $search): bool
    {
        $search = trim($search);
        if ($search === '') {
            return true;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $like = '%' . $queryBuilder->escapeLikeWildcards($search) . '%';

        return (int)$queryBuilder
            ->count('uid')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->like('header', $queryBuilder->createNamedParameter($like)),
                    $queryBuilder->expr()->like('subheader', $queryBuilder->createNamedParameter($like)),
                    $queryBuilder->expr()->like('bodytext', $queryBuilder->createNamedParameter($like))
                )
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne() > 0;
    }
}
