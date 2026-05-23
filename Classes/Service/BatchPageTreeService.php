<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

final class BatchPageTreeService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly BatchTranslationOptionService $optionService
    ) {}

    public function buildTreeData(int $targetLanguageId = 0): array
    {
        $site = $this->optionService->getPrimarySite();
        $rootPageId = $site !== null ? (int)$site->getRootPageId() : 1;
        $targetLanguage = $site !== null ? $this->optionService->getTargetLanguage($site, $targetLanguageId) : null;
        $targetLanguageId = $targetLanguage !== null ? (int)$targetLanguage->getLanguageId() : 0;

        $sourcePages = $this->fetchSourcePages();
        $pageTranslations = $targetLanguageId > 0 ? $this->fetchPageTranslations($targetLanguageId) : [];
        $pageUids = array_map(static fn(array $page): int => (int)$page['uid'], $sourcePages);
        $contentElements = $this->fetchContentElementsByPage($pageUids);
        $contentTranslations = $targetLanguageId > 0
            ? $this->fetchContentTranslations($this->collectContentUids($contentElements), $targetLanguageId)
            : [];

        $childrenByPid = [];
        $pagesByUid = [];
        foreach ($sourcePages as $page) {
            $pagesByUid[(int)$page['uid']] = $page;
            $childrenByPid[(int)$page['pid']][] = $page;
        }

        $tree = isset($pagesByUid[$rootPageId])
            ? [
                $this->buildPageNode(
                    $pagesByUid[$rootPageId],
                    $childrenByPid,
                    $pageTranslations,
                    $contentElements,
                    $contentTranslations,
                    0
                ),
            ]
            : $this->buildPageNodes(
                $rootPageId,
                $childrenByPid,
                $pageTranslations,
                $contentElements,
                $contentTranslations,
                0
            );

        $totals = $this->calculateTotals($tree);

        return [
            'blockedElements' => $totals['blockedElements'],
            'rootPageId' => $rootPageId,
            'targetLanguage' => $targetLanguage !== null ? $this->normalizeLanguage($targetLanguage) : null,
            'targetLanguageAvailable' => $targetLanguage !== null,
            'targetLanguageId' => $targetLanguageId,
            'totalElements' => $totals['totalElements'],
            'totalPages' => $totals['totalPages'],
            'tree' => $tree,
        ];
    }

    private function fetchSourcePages(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('uid', 'pid', 'title', 'nav_title', 'hidden', 'doktype', 'sorting')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->orderBy('pid', 'ASC')
            ->addOrderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    private function fetchPageTranslations(int $targetLanguageId): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('uid', 'l10n_parent')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($targetLanguageId, Connection::PARAM_INT)),
                $queryBuilder->expr()->gt('l10n_parent', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $translations = [];
        foreach ($rows as $row) {
            $translations[(int)$row['l10n_parent']] = (int)$row['uid'];
        }

        return $translations;
    }

    private function fetchContentElementsByPage(array $pageUids): array
    {
        $pageUids = array_values(array_unique(array_filter(array_map('intval', $pageUids))));
        if ($pageUids === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('uid', 'pid', 'CType', 'header', 'hidden', 'sorting')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY))
            )
            ->orderBy('pid', 'ASC')
            ->addOrderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $elementsByPage = [];
        foreach ($rows as $row) {
            $elementsByPage[(int)$row['pid']][] = $row;
        }

        return $elementsByPage;
    }

    private function fetchContentTranslations(array $contentUids, int $targetLanguageId): array
    {
        $contentUids = array_values(array_unique(array_filter(array_map('intval', $contentUids))));
        if ($contentUids === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('uid', 'l18n_parent')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($targetLanguageId, Connection::PARAM_INT)),
                $queryBuilder->expr()->gt('l18n_parent', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->in('l18n_parent', $queryBuilder->createNamedParameter($contentUids, Connection::PARAM_INT_ARRAY))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $translations = [];
        foreach ($rows as $row) {
            $translations[(int)$row['l18n_parent']] = (int)$row['uid'];
        }

        return $translations;
    }

    private function collectContentUids(array $contentElements): array
    {
        $uids = [];
        foreach ($contentElements as $pageElements) {
            foreach ($pageElements as $element) {
                $uids[] = (int)$element['uid'];
            }
        }

        return $uids;
    }

    private function buildPageNodes(
        int $parentId,
        array $childrenByPid,
        array $pageTranslations,
        array $contentElements,
        array $contentTranslations,
        int $level
    ): array {
        $nodes = [];

        foreach ($childrenByPid[$parentId] ?? [] as $page) {
            $nodes[] = $this->buildPageNode($page, $childrenByPid, $pageTranslations, $contentElements, $contentTranslations, $level);
        }

        return $nodes;
    }

    private function buildPageNode(
        array $page,
        array $childrenByPid,
        array $pageTranslations,
        array $contentElements,
        array $contentTranslations,
        int $level
    ): array {
        $pageUid = (int)$page['uid'];
        $elements = $this->buildElementNodes($contentElements[$pageUid] ?? [], $contentTranslations, $pageUid);
        $accessibleElements = count(array_filter($elements, static fn(array $element): bool => (bool)$element['accessible']));
        $blockedElements = count($elements) - $accessibleElements;
        $translatedElements = count(array_filter($elements, static fn(array $element): bool => (bool)$element['translated']));
        $pageTranslated = isset($pageTranslations[$pageUid]);
        $status = $this->resolveStatus($pageTranslated, $accessibleElements, $translatedElements);
        $children = $this->buildPageNodes($pageUid, $childrenByPid, $pageTranslations, $contentElements, $contentTranslations, $level + 1);

        return [
            'accessible' => $this->canReadPage($pageUid),
            'accessibleElementCount' => $accessibleElements,
            'blockedElementCount' => $blockedElements,
            'children' => $children,
            'elements' => $elements,
            'hidden' => (bool)($page['hidden'] ?? false),
            'indent' => $level * 18,
            'level' => $level,
            'pageTranslated' => $pageTranslated,
            'status' => $status,
            'statusLabelKey' => 'batch.status.' . $status,
            'title' => $this->pageTitle($page),
            'totalElementCount' => count($elements),
            'translatedElementCount' => $translatedElements,
            'translationUid' => $pageTranslations[$pageUid] ?? 0,
            'uid' => $pageUid,
        ];
    }

    private function buildElementNodes(array $elements, array $contentTranslations, int $pageUid): array
    {
        $nodes = [];

        foreach ($elements as $element) {
            $uid = (int)$element['uid'];
            $translated = isset($contentTranslations[$uid]);
            $status = $translated ? 'translated' : 'missing';

            $nodes[] = [
                'accessible' => $this->canEditContentOnPage($pageUid),
                'hidden' => (bool)($element['hidden'] ?? false),
                'status' => $status,
                'statusLabelKey' => 'batch.status.' . $status,
                'title' => $this->contentTitle($element),
                'translated' => $translated,
                'translationUid' => $contentTranslations[$uid] ?? 0,
                'type' => (string)($element['CType'] ?? ''),
                'uid' => $uid,
            ];
        }

        return $nodes;
    }

    private function resolveStatus(bool $pageTranslated, int $accessibleElements, int $translatedElements): string
    {
        if ($pageTranslated && $accessibleElements === $translatedElements) {
            return 'translated';
        }

        if ($pageTranslated || $translatedElements > 0) {
            return 'partial';
        }

        return 'missing';
    }

    private function calculateTotals(array $nodes): array
    {
        $totals = [
            'blockedElements' => 0,
            'totalElements' => 0,
            'totalPages' => 0,
        ];

        foreach ($nodes as $node) {
            $childTotals = $this->calculateTotals($node['children'] ?? []);
            $totals['blockedElements'] += (int)($node['blockedElementCount'] ?? 0) + $childTotals['blockedElements'];
            $totals['totalElements'] += (int)($node['totalElementCount'] ?? 0) + $childTotals['totalElements'];
            $totals['totalPages'] += 1 + $childTotals['totalPages'];
        }

        return $totals;
    }

    private function canReadPage(int $pageUid): bool
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser === null) {
            return false;
        }

        if ($backendUser->isAdmin()) {
            return true;
        }

        $permissionClause = $backendUser->getPagePermsClause(1);

        return BackendUtility::readPageAccess($pageUid, $permissionClause) !== false;
    }

    private function canEditContentOnPage(int $pageUid): bool
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser === null) {
            return false;
        }

        if ($backendUser->isAdmin()) {
            return true;
        }

        if (!$backendUser->check('tables_modify', 'tt_content')) {
            return false;
        }

        $permissionClause = $backendUser->getPagePermsClause(16);

        return BackendUtility::readPageAccess($pageUid, $permissionClause) !== false;
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $backendUser instanceof BackendUserAuthentication ? $backendUser : null;
    }

    private function pageTitle(array $page): string
    {
        $navTitle = trim((string)($page['nav_title'] ?? ''));
        if ($navTitle !== '') {
            return $navTitle;
        }

        $title = trim((string)($page['title'] ?? ''));

        return $title !== '' ? $title : 'Page ' . (int)($page['uid'] ?? 0);
    }

    private function contentTitle(array $element): string
    {
        $header = trim((string)($element['header'] ?? ''));
        $type = trim((string)($element['CType'] ?? ''));
        if ($header !== '') {
            return $header;
        }

        return $type !== '' ? $type : 'Content element ' . (int)($element['uid'] ?? 0);
    }

    private function normalizeLanguage(SiteLanguage $language): array
    {
        $locale = '';
        if (method_exists($language, 'getLocale')) {
            $localeValue = $language->getLocale();
            if (is_scalar($localeValue)) {
                $locale = (string)$localeValue;
            } elseif (is_object($localeValue) && method_exists($localeValue, 'getName')) {
                $locale = (string)$localeValue->getName();
            }
        }

        return [
            'id' => (int)$language->getLanguageId(),
            'locale' => $locale,
            'title' => method_exists($language, 'getTitle') ? (string)$language->getTitle() : 'Language ' . (int)$language->getLanguageId(),
        ];
    }
}
