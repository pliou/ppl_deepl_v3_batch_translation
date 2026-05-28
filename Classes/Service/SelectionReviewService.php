<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service;

use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\BatchSelection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class SelectionReviewService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly RecordLocalizationService $localizationService,
        private readonly TranslationFieldDefinitionService $fieldDefinitionService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildReview(BatchSelection $selection, int $targetLanguageId, int $rootPageUid): array
    {
        $pages = $this->fetchDefaultPages();
        $children = $this->childrenByPid($pages);
        $outlines = $this->outlineNumbers($pages, $children, $rootPageUid);
        $includedPages = [];
        $includedElements = [];
        $excludedPages = [];
        $excludedElements = [];

        foreach ($selection->selectedSubtrees as $subtree) {
            $rootLabel = $this->pageLabel($pages[$subtree->rootPageUid] ?? null);
            foreach ($this->subtreePages($pages, $children, $subtree->rootPageUid, $subtree->includeRoot, $subtree->includeHidden) as $page) {
                $pageUid = (int)$page['uid'];
                if ($selection->hasExcludedPage($pageUid)) {
                    $excludedPages[$pageUid] = $this->pageSummary($page, $outlines, 'Excluded from recursive branch');
                    continue;
                }

                if (!isset($includedPages[$pageUid])) {
                    $includedPages[$pageUid] = [
                        'origin' => $pageUid === $subtree->rootPageUid ? 'direct' : 'inherited',
                        'mode' => $pageUid === $subtree->rootPageUid ? 'Recursive branch' : 'Inherited from recursive branch',
                        'via' => sprintf('#%d %s', $subtree->rootPageUid, $rootLabel),
                    ];
                }

                if ($subtree->includeElements) {
                    foreach ($this->fetchPageContentElements($pageUid) as $index => $element) {
                        $contentUid = (int)$element['uid'];
                        if ($selection->hasExcludedElement($contentUid)) {
                            $excludedElements[$contentUid] = $this->elementSummary($element, $outlines, $index + 1, $targetLanguageId, 'Excluded from recursive branch');
                            continue;
                        }
                        $includedElements[$contentUid] ??= [
                            'row' => $element,
                            'origin' => 'inherited',
                            'via' => sprintf('#%d %s', $subtree->rootPageUid, $rootLabel),
                        ];
                    }
                }
            }
        }

        foreach ($selection->selectedPages as $pageSelection) {
            $pageUid = $pageSelection->pageUid;
            $page = $pages[$pageUid] ?? $this->fetchRecord('pages', $pageUid);
            if ($page === null) {
                continue;
            }
            if ($selection->hasExcludedPage($pageUid)) {
                $excludedPages[$pageUid] = $this->pageSummary($page, $outlines, 'Excluded');
                continue;
            }

            $includedPages[$pageUid] = [
                'origin' => 'direct',
                'mode' => 'Only this page',
                'via' => '',
            ];

            if ($pageSelection->includeElements) {
                foreach ($this->fetchPageContentElements($pageUid) as $index => $element) {
                    $contentUid = (int)$element['uid'];
                    if ($selection->hasExcludedElement($contentUid)) {
                        $excludedElements[$contentUid] = $this->elementSummary($element, $outlines, $index + 1, $targetLanguageId, 'Excluded from only-page selection');
                        continue;
                    }
                    $includedElements[$contentUid] ??= [
                        'row' => $element,
                        'origin' => 'inherited',
                        'via' => sprintf('#%d %s', $pageUid, $this->pageLabel($page)),
                    ];
                }
            }
        }

        foreach ($selection->selectedElements as $elementSelection) {
            $element = $this->fetchRecord('tt_content', $elementSelection->contentUid);
            if ($element === null) {
                continue;
            }
            $pageUid = (int)$element['pid'];
            if ($selection->hasExcludedPage($pageUid) || $selection->hasExcludedElement((int)$element['uid'])) {
                $excludedElements[(int)$element['uid']] = $this->elementSummary($element, $outlines, $this->contentElementPosition($element), $targetLanguageId, 'Excluded');
                continue;
            }

            if (!isset($includedPages[$pageUid])) {
                $includedPages[$pageUid] = [
                    'origin' => 'direct',
                    'mode' => 'Element-only page context',
                    'via' => '',
                ];
            }
            $includedElements[(int)$element['uid']] = [
                'row' => $element,
                'origin' => 'direct',
                'via' => '',
            ];
        }

        $groups = [];
        foreach ($this->sortPageUidsByOutline(array_keys($includedPages), $outlines) as $pageUid) {
            $page = $pages[$pageUid] ?? $this->fetchRecord('pages', $pageUid);
            if ($page === null) {
                continue;
            }

            $pageElements = [];
            foreach ($this->fetchPageContentElements($pageUid) as $index => $element) {
                $contentUid = (int)$element['uid'];
                if (!isset($includedElements[$contentUid])) {
                    continue;
                }
                $summary = $this->elementSummary($element, $outlines, $index + 1, $targetLanguageId, '');
                $summary['origin'] = $includedElements[$contentUid]['origin'];
                $summary['via'] = $includedElements[$contentUid]['via'];
                $pageElements[] = $summary;
            }

            $targetPage = $this->targetRecord('pages', $pageUid, $targetLanguageId);
            $currentOperations = $this->currentOperationsForRecord('pages', $page, $targetPage);
            $pageStatus = $this->aggregatePageStatus(
                $this->statusForRecord('pages', $page, $targetPage),
                array_map(static fn(array $element): string => (string)($element['status'] ?? 'missing'), $pageElements)
            );
            $groups[] = [
                'uid' => $pageUid,
                'outline' => $outlines[$pageUid] ?? (string)$pageUid,
                'label' => sprintf('#%d %s', $pageUid, $this->pageLabel($page)),
                'mode' => $includedPages[$pageUid]['mode'],
                'origin' => $includedPages[$pageUid]['origin'],
                'via' => $includedPages[$pageUid]['via'],
                'status' => $pageStatus,
                'hasCurrentTranslation' => $currentOperations !== [],
                'currentOperations' => $currentOperations,
                'elementCount' => count($pageElements),
                'elements' => $pageElements,
            ];
        }

        return [
            'groups' => $groups,
            'excludedPages' => array_values($excludedPages),
            'excludedElements' => array_values($excludedElements),
            'counts' => [
                'pages' => count($groups),
                'elements' => count($includedElements),
                'excludedPages' => count($excludedPages),
                'excludedElements' => count($excludedElements),
            ],
            'isEmpty' => $groups === [],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $pages
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function childrenByPid(array $pages): array
    {
        $children = [];
        foreach ($pages as $page) {
            $children[(int)$page['pid']][] = $page;
        }

        return $children;
    }

    /**
     * @param array<int, array<string, mixed>> $pages
     * @param array<int, array<int, array<string, mixed>>> $children
     * @return array<int, string>
     */
    private function outlineNumbers(array $pages, array $children, int $rootPageUid): array
    {
        $outlines = [];
        $roots = $rootPageUid > 0 && isset($pages[$rootPageUid]) ? [$pages[$rootPageUid]] : ($children[0] ?? []);
        foreach (array_values($roots) as $index => $root) {
            $this->appendOutline($outlines, $children, $root, (string)($index + 1));
        }

        return $outlines;
    }

    /**
     * @param array<int, string> $outlines
     * @param array<int, array<int, array<string, mixed>>> $children
     * @param array<string, mixed> $page
     */
    private function appendOutline(array &$outlines, array $children, array $page, string $outline): void
    {
        $uid = (int)$page['uid'];
        $outlines[$uid] = $outline;
        foreach (array_values($children[$uid] ?? []) as $index => $child) {
            $this->appendOutline($outlines, $children, $child, $outline . '.' . ($index + 1));
        }
    }

    /**
     * @param array<int, array<string, mixed>> $pages
     * @param array<int, array<int, array<string, mixed>>> $children
     * @return array<int, array<string, mixed>>
     */
    private function subtreePages(array $pages, array $children, int $rootPageUid, bool $includeRoot, bool $includeHidden): array
    {
        $result = [];
        $stack = [];
        if ($includeRoot && isset($pages[$rootPageUid])) {
            $stack[] = $pages[$rootPageUid];
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
     * @param int[] $pageUids
     * @param array<int, string> $outlines
     * @return int[]
     */
    private function sortPageUidsByOutline(array $pageUids, array $outlines): array
    {
        usort($pageUids, static function (int $left, int $right) use ($outlines): int {
            return strnatcmp($outlines[$left] ?? (string)$left, $outlines[$right] ?? (string)$right);
        });

        return $pageUids;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchDefaultPages(): array
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
        if ($uid <= 0) {
            return null;
        }

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

    private function targetRecord(string $table, int $sourceUid, int $targetLanguageId): ?array
    {
        $targetUid = $this->localizationService->findLocalizedRecordUid($table, $sourceUid, $targetLanguageId);

        return $targetUid > 0 ? $this->fetchRecord($table, $targetUid) : null;
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

    /**
     * @param string[] $elementStatuses
     */
    private function aggregatePageStatus(string $pageStatus, array $elementStatuses): string
    {
        if ($pageStatus === 'hidden') {
            return $pageStatus;
        }

        $hasComplete = $pageStatus === 'translated';
        $hasIncomplete = $pageStatus !== 'translated';
        foreach ($elementStatuses as $status) {
            if ($status === 'translated') {
                $hasComplete = true;
            } else {
                $hasIncomplete = true;
            }
        }

        return $hasComplete && $hasIncomplete ? 'partial' : $pageStatus;
    }

    /**
     * @param array<int, string> $outlines
     * @return array<string, mixed>
     */
    private function pageSummary(array $page, array $outlines, string $reason): array
    {
        $uid = (int)$page['uid'];

        return [
            'type' => 'page',
            'uid' => $uid,
            'outline' => $outlines[$uid] ?? (string)$uid,
            'label' => sprintf('#%d %s', $uid, $this->pageLabel($page)),
            'reason' => $reason,
        ];
    }

    /**
     * @param array<int, string> $outlines
     * @return array<string, mixed>
     */
    private function elementSummary(array $element, array $outlines, int $position, int $targetLanguageId, string $reason): array
    {
        $pageUid = (int)$element['pid'];
        $title = $this->contentTitle($element);
        $targetRecord = $this->targetRecord('tt_content', (int)$element['uid'], $targetLanguageId);
        $currentOperations = $this->currentOperationsForRecord('tt_content', $element, $targetRecord);

        return [
            'type' => 'element',
            'uid' => (int)$element['uid'],
            'pageUid' => $pageUid,
            'outline' => ($outlines[$pageUid] ?? (string)$pageUid) . '-e' . $position,
            'label' => sprintf('#%d %s', (int)$element['uid'], mb_substr($title, 0, 50)),
            'fullTitle' => $title,
            'ctype' => (string)($element['CType'] ?? ''),
            'status' => $this->statusForRecord('tt_content', $element, $targetRecord),
            'hasCurrentTranslation' => $currentOperations !== [],
            'currentOperations' => $currentOperations,
            'preview' => mb_substr($this->contentPreview($element), 0, 150),
            'reason' => $reason,
        ];
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

    private function previewValue(string $value): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($value)) ?? ''), 0, 180);
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

    private function pageLabel(?array $page): string
    {
        return trim((string)($page['title'] ?? '')) ?: 'Untitled page';
    }

    private function contentTitle(?array $element): string
    {
        if ($element === null) {
            return 'Content element';
        }
        foreach (['header', 'subheader', 'bodytext'] as $field) {
            $value = trim(strip_tags((string)($element[$field] ?? '')));
            if ($value !== '') {
                return $value;
            }
        }

        return (string)($element['CType'] ?? 'Content element');
    }

    private function contentPreview(array $element): string
    {
        foreach (['bodytext', 'subheader', 'header'] as $field) {
            $value = trim(preg_replace('/\s+/', ' ', strip_tags((string)($element[$field] ?? ''))) ?? '');
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function contentElementPosition(array $element): int
    {
        $elements = $this->fetchPageContentElements((int)$element['pid']);
        foreach ($elements as $index => $candidate) {
            if ((int)$candidate['uid'] === (int)$element['uid']) {
                return $index + 1;
            }
        }

        return 1;
    }
}
