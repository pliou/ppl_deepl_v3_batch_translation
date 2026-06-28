<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service;

use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\BatchSelection;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\ElementSelection;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\PageSelection;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\PreflightPlan;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\SubtreeSelection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

final class SelectionBasketSummaryService
{
    use PageScanLimitTrait;

    private const PAGE_SCAN_LIMIT = 3000;

    public function __construct(
        private readonly ConnectionPool $connectionPool
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summarize(BatchSelection $selection, ?PreflightPlan $plan = null): array
    {
        $groups = [
            'pages' => [],
            'subtrees' => [],
            'elements' => [],
        ];
        foreach ($selection->selectedPages as $page) {
            $pageRow = $this->fetchPage($page->pageUid);
            $elementCount = $page->includeElements ? $this->countPageContentElements($page->pageUid) : 0;
            $groups['pages'][] = [
                'type' => 'page',
                'uid' => $page->pageUid,
                'label' => sprintf('#%d %s', $page->pageUid, $this->pageTitle($pageRow)),
                'scope' => $this->translate('scope.pageOnly'),
                'meta' => sprintf($this->translate($elementCount === 1 ? 'summary.onePageOneElementPattern' : 'summary.onePageElementsPattern'), $elementCount),
                'value' => (string)$page->pageUid,
            ];
        }
        foreach ($selection->selectedSubtrees as $subtree) {
            $pageRow = $this->fetchPage($subtree->rootPageUid);
            $scopeCounts = $this->countSubtreeScope($subtree);
            $groups['subtrees'][] = [
                'type' => 'subtree',
                'uid' => $subtree->rootPageUid,
                'label' => sprintf('#%d %s', $subtree->rootPageUid, $this->pageTitle($pageRow)),
                'scope' => $this->translate('scope.branchChildren'),
                'meta' => sprintf($this->translate('summary.scopeCountsPattern'), $scopeCounts['pages'], $scopeCounts['elements']),
                'value' => (string)$subtree->rootPageUid,
            ];
        }
        foreach ($selection->selectedElements as $element) {
            $elementRow = $this->fetchContentElement($element->contentUid);
            $pageUid = $element->sourcePageUid > 0 ? $element->sourcePageUid : (int)($elementRow['pid'] ?? 0);
            $pageRow = $pageUid > 0 ? $this->fetchPage($pageUid) : null;
            $groups['elements'][] = [
                'type' => 'element',
                'uid' => $element->contentUid,
                'label' => sprintf('#%d %s', $element->contentUid, $this->contentTitle($elementRow)),
                'scope' => $this->translate('scope.elementOnly'),
                'meta' => $pageUid > 0 ? sprintf($this->translate('summary.onPagePattern'), $pageUid, $this->pageTitle($pageRow)) : $this->translate('summary.singleContentElement'),
                'value' => $element->contentUid . ':' . $element->sourcePageUid,
            ];
        }

        $counts = [
            'pages' => count($selection->selectedPages),
            'subtrees' => count($selection->selectedSubtrees),
            'elements' => count($selection->selectedElements),
            'blocked' => 0,
            'alreadyTranslated' => 0,
            'missing' => 0,
            'fields' => 0,
            'createRecords' => 0,
            'updateRecords' => 0,
            'skipped' => 0,
            'overwrites' => 0,
            'characters' => 0,
            'resolvedPages' => 0,
            'resolvedElements' => 0,
        ];

        if ($plan instanceof PreflightPlan) {
            $planCounts = $plan->counts();
            $counts['blocked'] = $planCounts['blocked'];
            $counts['missing'] = $planCounts['createRecords'];
            $counts['fields'] = $planCounts['fields'];
            $counts['alreadyTranslated'] = $planCounts['skipped'];
            $counts['createRecords'] = $planCounts['createRecords'];
            $counts['updateRecords'] = $planCounts['updateRecords'];
            $counts['skipped'] = $planCounts['skipped'];
            $counts['overwrites'] = $planCounts['overwrites'];
            $counts['characters'] = $planCounts['characters'];
            $counts['resolvedPages'] = $planCounts['pages'];
            $counts['resolvedElements'] = $planCounts['elements'];
        }

        $rows = array_merge($groups['pages'], $groups['subtrees'], $groups['elements']);
        $pendingFields = $plan instanceof PreflightPlan ? $this->pendingFields($plan) : [];

        return [
            'rows' => $rows,
            'groups' => $groups,
            'counts' => $counts,
            'pendingFields' => $pendingFields,
            'pendingFieldGroups' => $this->groupPendingFields($pendingFields),
            'isEmpty' => $rows === [],
            'hasPreflight' => $plan instanceof PreflightPlan,
        ];
    }

    /**
     * @return array<int, array<string, string|int>>
     */
    private function pendingFields(PreflightPlan $plan): array
    {
        $fields = [];
        foreach ($plan->items as $item) {
            foreach ($item->writableFieldOperations() as $operation) {
                $recordType = $item->itemType === 'page' ? $this->translate('label.page') : $this->translate('label.element');
                $fields[] = [
                    'recordType' => $recordType,
                    'recordUid' => $item->effectiveBaseUid(),
                    'recordLabel' => sprintf($this->translate('summary.recordLabelPattern'), $recordType, $item->effectiveBaseUid(), $item->label),
                    'field' => $operation->field,
                    'fieldLabel' => $operation->label !== '' ? $operation->label : $operation->field,
                    'writeAction' => $operation->writeAction,
                    'actionLabelKey' => $this->actionLabelKey($operation->writeAction),
                    'sourcePreview' => $this->preview($operation->sourceValue),
                ];
            }
        }

        return $fields;
    }

    /**
     * @param array<int, array<string, string|int>> $pendingFields
     * @return array<int, array{recordLabel: string, fields: array<int, array<string, string|int>>, fieldCount: int}>
     */
    private function groupPendingFields(array $pendingFields): array
    {
        $groups = [];
        foreach ($pendingFields as $field) {
            $recordLabel = (string)($field['recordLabel'] ?? '');
            if ($recordLabel === '') {
                continue;
            }
            $groups[$recordLabel]['recordLabel'] = $recordLabel;
            $groups[$recordLabel]['fields'][] = $field;
        }

        foreach ($groups as $recordLabel => $group) {
            $groups[$recordLabel]['fieldCount'] = count($group['fields']);
        }

        return array_values($groups);
    }

    private function actionLabelKey(string $writeAction): string
    {
        $key = match ($writeAction) {
            'fill_empty' => 'label.operationFillEmpty',
            'overwrite' => 'label.operationOverwrite',
            default => 'label.operationWillWrite',
        };

        return 'LLL:EXT:ppl_deepl_v3_batch_translation/Resources/Private/Language/locallang.xlf:' . $key;
    }

    private function fetchPage(int $uid): ?array
    {
        return $this->fetchRecord('pages', $uid);
    }

    private function fetchContentElement(int $uid): ?array
    {
        return $this->fetchRecord('tt_content', $uid);
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
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, \Doctrine\DBAL\ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    private function pageTitle(?array $page): string
    {
        $title = trim((string)($page['title'] ?? ''));

        return $title !== '' ? $title : $this->translate('label.untitledPage');
    }

    private function contentTitle(?array $element): string
    {
        if ($element === null) {
            return $this->translate('label.contentElement');
        }

        foreach (['header', 'subheader', 'bodytext'] as $field) {
            $value = trim(strip_tags((string)($element[$field] ?? '')));
            if ($value !== '') {
                return mb_substr($value, 0, 80);
            }
        }

        return (string)($element['CType'] ?? $this->translate('label.contentElement'));
    }

    private function preview(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', strip_tags($value)) ?? '');

        return mb_strlen($value) > 90 ? mb_substr($value, 0, 87) . '...' : $value;
    }

    private function translate(string $key): string
    {
        return LocalizationUtility::translate($key, 'ppl_deepl_v3_batch_translation') ?? $key;
    }

    private function countPageContentElements(int $pageUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->count('uid')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, \Doctrine\DBAL\ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return array{pages: int, elements: int}
     */
    private function countSubtreeScope(SubtreeSelection $subtree): array
    {
        $pages = $this->fetchDefaultPages();
        $children = [];
        foreach ($pages as $page) {
            $children[(int)$page['pid']][] = $page;
        }

        $stack = [];
        if ($subtree->includeRoot && isset($pages[$subtree->rootPageUid])) {
            $stack[] = $pages[$subtree->rootPageUid];
        } else {
            $stack = $children[$subtree->rootPageUid] ?? [];
        }

        $pageCount = 0;
        $elementCount = 0;
        while ($stack !== []) {
            $page = array_shift($stack);
            if ($subtree->includeHidden || empty($page['hidden'])) {
                $pageCount++;
                if ($subtree->includeElements) {
                    $elementCount += $this->countPageContentElements((int)$page['uid']);
                }
            }

            foreach ($children[(int)$page['uid']] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return [
            'pages' => $pageCount,
            'elements' => $elementCount,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchDefaultPages(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('uid', 'pid', 'title', 'hidden')
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
}
