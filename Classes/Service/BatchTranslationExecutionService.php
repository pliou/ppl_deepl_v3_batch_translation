<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service;

use Ppl\PplDeeplV3Requests\Service\DeeplApiClientService;
use Ppl\PplDeeplV3Requests\Service\DeeplConfigurationService;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

final class BatchTranslationExecutionService
{
    private const PAGE_FIELDS = [
        'title',
        'nav_title',
        'subtitle',
        'description',
        'abstract',
        'keywords',
        'seo_title',
        'og_title',
        'og_description',
        'twitter_title',
        'twitter_description',
    ];

    private const CONTENT_FIELDS = [
        'header',
        'subheader',
        'bodytext',
        'imagecaption',
        'imagealttext',
        'imagetitletext',
        'table_caption',
    ];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly BatchTranslationOptionService $optionService,
        private readonly DeeplConfigurationService $configurationService,
        private readonly DeeplApiClientService $apiClient
    ) {}

    public function execute(array $body): array
    {
        $action = trim((string)($body['batch_action'] ?? ''));
        if ($action === '' || $action === 'refresh') {
            return [];
        }

        $backendUser = $this->getBackendUser();
        if ($backendUser === null) {
            return [[
                'type' => 'error',
                'text' => $this->translate('batch.error.noBackendUser'),
            ]];
        }

        $site = $this->optionService->getPrimarySite();
        if ($site === null) {
            return [[
                'type' => 'error',
                'text' => $this->translate('batch.error.noSite'),
            ]];
        }

        $sourceLanguage = $this->optionService->getSourceLanguage($site);
        $targetLanguage = $this->optionService->getTargetLanguage($site, $this->optionService->resolveTargetLanguageId($body));
        if ($sourceLanguage === null || $targetLanguage === null) {
            return [[
                'type' => 'error',
                'text' => $this->translate('batch.error.noLanguages'),
            ]];
        }

        $sourceLanguageCode = $this->optionService->toDeepLSourceLanguage($sourceLanguage);
        $targetLanguageCode = $this->optionService->toDeepLTargetLanguage($targetLanguage);
        if ($sourceLanguageCode === '' || $targetLanguageCode === '' || $sourceLanguageCode === $targetLanguageCode) {
            return [[
                'type' => 'error',
                'text' => $this->translate('batch.error.invalidLanguageMapping'),
            ]];
        }

        $authKey = $this->configurationService->getAuthKey();
        if ($authKey === '') {
            return [[
                'type' => 'error',
                'text' => $this->translate('batch.error.missingAuthKey'),
            ]];
        }

        $selection = $this->resolveSelection($body, $action);
        if (!$selection['valid']) {
            return [[
                'type' => 'warning',
                'text' => $this->translate('batch.warning.noSelection'),
            ]];
        }

        $stats = [
            'blocked' => 0,
            'elements' => 0,
            'errors' => [],
            'pages' => 0,
            'skipped' => 0,
        ];

        $sourcePages = $this->fetchSourcePages();
        $childrenByPid = $this->groupPagesByParent($sourcePages);
        $selectedPageUids = $this->expandSelectedPages($selection['pageUids'], $selection['subtreePageUids'], $childrenByPid);
        $selectedPageLookup = array_fill_keys($selectedPageUids, true);
        $pageUids = $selectedPageUids;
        $selectedElements = $this->fetchContentElementsByUid($selection['elementUids']);
        foreach ($selectedElements as $element) {
            $pageUids[] = (int)$element['pid'];
        }
        $pageUids = array_values(array_unique(array_filter(array_map('intval', $pageUids))));

        if ($pageUids === [] && $selectedElements === []) {
            return [[
                'type' => 'warning',
                'text' => $this->translate('batch.warning.noSourceRecords'),
            ]];
        }

        $targetLanguageId = (int)$targetLanguage->getLanguageId();
        $glossaryId = $this->optionService->resolveGlossaryId($sourceLanguageCode, $targetLanguageCode, (string)($body['glossary_id'] ?? ''));
        $styleRuleId = $this->optionService->resolveStyleRuleId($targetLanguageCode, (string)($body['style_rule_id'] ?? ''));
        $customInstructions = $this->optionService->normalizeCustomInstructions((string)($body['custom_instructions'] ?? ''));
        $elementUidsByPage = $this->groupSelectedElementUidsByPage($selectedElements);

        foreach ($pageUids as $pageUid) {
            $sourcePage = $this->fetchRecord('pages', $pageUid);
            if ($sourcePage === null) {
                $stats['skipped']++;
                continue;
            }

            try {
                $pageResult = $this->translatePageRecord(
                    $sourcePage,
                    $targetLanguageId,
                    $sourceLanguageCode,
                    $targetLanguageCode,
                    $authKey,
                    $glossaryId,
                    $styleRuleId,
                    $customInstructions,
                    (bool)$selection['missingOnly'],
                    $backendUser
                );
            } catch (\Throwable $exception) {
                $pageResult = ['error' => $this->translate('batch.error.page', [$pageUid, $exception->getMessage()])];
            }
            $this->mergeRecordResult($stats, $pageResult, 'pages');

            $contentElements = isset($elementUidsByPage[$pageUid]) && !isset($selectedPageLookup[$pageUid])
                ? $this->fetchContentElementsByUid($elementUidsByPage[$pageUid])
                : $this->fetchContentElementsByPage($pageUid);

            foreach ($contentElements as $contentElement) {
                try {
                    $elementResult = $this->translateContentRecord(
                        $contentElement,
                        $targetLanguageId,
                        $sourceLanguageCode,
                        $targetLanguageCode,
                        $authKey,
                        $glossaryId,
                        $styleRuleId,
                        $customInstructions,
                        (bool)$selection['missingOnly'],
                        $backendUser
                    );
                } catch (\Throwable $exception) {
                    $elementResult = ['error' => $this->translate('batch.error.element', [(int)$contentElement['uid'], $exception->getMessage()])];
                }
                $this->mergeRecordResult($stats, $elementResult, 'elements');
            }
        }

        return $this->buildMessages($stats);
    }

    private function resolveSelection(array $body, string $action): array
    {
        $selection = [
            'elementUids' => [],
            'missingOnly' => false,
            'pageUids' => [],
            'subtreePageUids' => [],
            'valid' => false,
        ];

        foreach (['translate_page', 'retranslate_page', 'translate_missing'] as $prefix) {
            if (!str_starts_with($action, $prefix . ':')) {
                continue;
            }

            $uid = (int)substr($action, strlen($prefix) + 1);
            if ($uid > 0) {
                $selection['pageUids'][] = $uid;
                $selection['missingOnly'] = $prefix === 'translate_missing';
                $selection['valid'] = true;
            }

            return $selection;
        }

        if (in_array($action, ['translate_selected', 'translate_selected_missing'], true)) {
            $selection['pageUids'] = $this->intList($body['selected_pages'] ?? []);
            $selection['subtreePageUids'] = $this->intList($body['selected_subtree_pages'] ?? []);
            $selection['elementUids'] = $this->intList($body['selected_elements'] ?? []);
            $selection['missingOnly'] = $action === 'translate_selected_missing';
            $selection['valid'] = $selection['pageUids'] !== []
                || $selection['subtreePageUids'] !== []
                || $selection['elementUids'] !== [];
        }

        return $selection;
    }

    private function translatePageRecord(
        array $sourceRecord,
        int $targetLanguageId,
        string $sourceLanguageCode,
        string $targetLanguageCode,
        string $authKey,
        string $glossaryId,
        string $styleRuleId,
        array $customInstructions,
        bool $missingOnly,
        BackendUserAuthentication $backendUser
    ): array {
        $sourceUid = (int)$sourceRecord['uid'];
        if (!$this->canEditPage($sourceUid, $backendUser)) {
            return ['blocked' => true];
        }

        $translationUid = $this->findTranslationUid('pages', $sourceRecord, $targetLanguageId);
        if ($missingOnly && $translationUid > 0) {
            return ['skipped' => true];
        }

        if ($translationUid === 0) {
            $translationUid = $this->localizeRecord('pages', $sourceUid, $targetLanguageId, $backendUser);
        }

        if ($translationUid <= 0) {
            return ['error' => $this->translate('batch.error.localizePage', [$sourceUid])];
        }

        $values = $this->translateFieldValues('pages', $sourceRecord, $authKey, $sourceLanguageCode, $targetLanguageCode, $glossaryId, $styleRuleId, $customInstructions);
        if ($values !== []) {
            $this->updateRecord('pages', $translationUid, $values, $backendUser);
        }

        return ['translated' => true];
    }

    private function translateContentRecord(
        array $sourceRecord,
        int $targetLanguageId,
        string $sourceLanguageCode,
        string $targetLanguageCode,
        string $authKey,
        string $glossaryId,
        string $styleRuleId,
        array $customInstructions,
        bool $missingOnly,
        BackendUserAuthentication $backendUser
    ): array {
        $sourceUid = (int)$sourceRecord['uid'];
        $pageUid = (int)$sourceRecord['pid'];
        if (!$this->canEditContentOnPage($pageUid, $backendUser)) {
            return ['blocked' => true];
        }

        $translationUid = $this->findTranslationUid('tt_content', $sourceRecord, $targetLanguageId);
        if ($missingOnly && $translationUid > 0) {
            return ['skipped' => true];
        }

        if ($translationUid === 0) {
            $translationUid = $this->localizeRecord('tt_content', $sourceUid, $targetLanguageId, $backendUser);
        }

        if ($translationUid <= 0) {
            return ['error' => $this->translate('batch.error.localizeElement', [$sourceUid])];
        }

        $values = $this->translateFieldValues('tt_content', $sourceRecord, $authKey, $sourceLanguageCode, $targetLanguageCode, $glossaryId, $styleRuleId, $customInstructions);
        if ($values !== []) {
            $this->updateRecord('tt_content', $translationUid, $values, $backendUser);
        }

        return ['translated' => true];
    }

    private function translateFieldValues(
        string $table,
        array $sourceRecord,
        string $authKey,
        string $sourceLanguageCode,
        string $targetLanguageCode,
        string $glossaryId,
        string $styleRuleId,
        array $customInstructions
    ): array {
        $plainTexts = [];
        $htmlTexts = [];

        foreach ($this->getTranslatableFields($table, $sourceRecord) as $fieldName => $value) {
            if ($this->looksLikeHtml($value)) {
                $htmlTexts[$fieldName] = $value;
            } else {
                $plainTexts[$fieldName] = $value;
            }
        }

        return $this->translateTextMap($plainTexts, $authKey, $sourceLanguageCode, $targetLanguageCode, $glossaryId, $styleRuleId, $customInstructions, '')
            + $this->translateTextMap($htmlTexts, $authKey, $sourceLanguageCode, $targetLanguageCode, $glossaryId, $styleRuleId, $customInstructions, 'html');
    }

    /**
     * @param array<string, string> $texts
     * @return array<string, string>
     */
    private function translateTextMap(
        array $texts,
        string $authKey,
        string $sourceLanguageCode,
        string $targetLanguageCode,
        string $glossaryId,
        string $styleRuleId,
        array $customInstructions,
        string $tagHandling
    ): array {
        if ($texts === []) {
            return [];
        }

        $translatedValues = [];
        foreach (array_chunk($texts, 25, true) as $chunk) {
            $fieldNames = array_keys($chunk);
            try {
                $translations = $this->apiClient->translateTexts(
                    $authKey,
                    array_values($chunk),
                    $sourceLanguageCode,
                    $targetLanguageCode,
                    $glossaryId !== '' ? $glossaryId : null,
                    $styleRuleId,
                    $customInstructions,
                    $tagHandling
                );
            } catch (\RuntimeException $exception) {
                if ($tagHandling !== 'html') {
                    throw $exception;
                }

                $translations = $this->apiClient->translateTexts(
                    $authKey,
                    array_values($chunk),
                    $sourceLanguageCode,
                    $targetLanguageCode,
                    $glossaryId !== '' ? $glossaryId : null,
                    $styleRuleId,
                    $customInstructions
                );
            }

            foreach ($fieldNames as $index => $fieldName) {
                if (array_key_exists($index, $translations)) {
                    $translatedValues[$fieldName] = $translations[$index];
                }
            }
        }

        return $translatedValues;
    }

    /**
     * @return array<string, string>
     */
    private function getTranslatableFields(string $table, array $sourceRecord): array
    {
        $fieldNames = $table === 'pages' ? self::PAGE_FIELDS : self::CONTENT_FIELDS;
        $values = [];

        foreach ($fieldNames as $fieldName) {
            if (!array_key_exists($fieldName, $sourceRecord) || !isset($GLOBALS['TCA'][$table]['columns'][$fieldName])) {
                continue;
            }

            $type = (string)($GLOBALS['TCA'][$table]['columns'][$fieldName]['config']['type'] ?? '');
            if (!in_array($type, ['input', 'text'], true)) {
                continue;
            }

            $value = trim((string)$sourceRecord[$fieldName]);
            if ($value !== '') {
                $values[$fieldName] = $value;
            }
        }

        return $values;
    }

    private function localizeRecord(string $table, int $uid, int $targetLanguageId, BackendUserAuthentication $backendUser): int
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [], $backendUser);

        $reflection = new \ReflectionProperty($dataHandler, 'useTransOrigPointerField');
        $previousValue = $reflection->getValue($dataHandler);
        $reflection->setValue($dataHandler, true);
        try {
            $newUid = $dataHandler->localize($table, $uid, $targetLanguageId);
        } finally {
            $reflection->setValue($dataHandler, $previousValue);
        }

        $this->assertDataHandlerSuccess($dataHandler);

        return (int)$newUid;
    }

    private function updateRecord(string $table, int $uid, array $values, BackendUserAuthentication $backendUser): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$table => [$uid => $values]], [], $backendUser);
        $dataHandler->process_datamap();
        $this->assertDataHandlerSuccess($dataHandler);
    }

    private function assertDataHandlerSuccess(DataHandler $dataHandler): void
    {
        if (($dataHandler->errorLog ?? []) !== []) {
            throw new \RuntimeException(implode(' ', array_map('strval', $dataHandler->errorLog)));
        }
    }

    private function mergeRecordResult(array &$stats, array $result, string $counterKey): void
    {
        if (($result['blocked'] ?? false) === true) {
            $stats['blocked']++;
            return;
        }

        if (($result['skipped'] ?? false) === true) {
            $stats['skipped']++;
            return;
        }

        if (isset($result['error'])) {
            $stats['errors'][] = (string)$result['error'];
            return;
        }

        if (($result['translated'] ?? false) === true) {
            $stats[$counterKey]++;
        }
    }

    private function buildMessages(array $stats): array
    {
        $messages = [];

        if ($stats['errors'] === []) {
            $messages[] = [
                'type' => 'success',
                'text' => $this->translate('batch.result.success', [$stats['pages'], $stats['elements']]),
            ];
        } else {
            foreach (array_slice($stats['errors'], 0, 5) as $error) {
                $messages[] = [
                    'type' => 'error',
                    'text' => $error,
                ];
            }
        }

        if ($stats['blocked'] > 0) {
            $messages[] = [
                'type' => 'warning',
                'text' => $this->translate('batch.result.blocked', [$stats['blocked']]),
            ];
        }

        if ($stats['skipped'] > 0) {
            $messages[] = [
                'type' => 'info',
                'text' => $this->translate('batch.result.skipped', [$stats['skipped']]),
            ];
        }

        return $messages;
    }

    private function findTranslationUid(string $table, array $sourceRecord, int $targetLanguageId): int
    {
        $languageField = (string)($GLOBALS['TCA'][$table]['ctrl']['languageField'] ?? 'sys_language_uid');
        $parentField = (string)($GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'] ?? ($table === 'pages' ? 'l10n_parent' : 'l18n_parent'));
        $sourceUid = (int)$sourceRecord['uid'];

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder
            ->select('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq($languageField, $queryBuilder->createNamedParameter($targetLanguageId, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq($parentField, $queryBuilder->createNamedParameter($sourceUid, Connection::PARAM_INT))
            )
            ->setMaxResults(1);

        if ($table !== 'pages') {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter((int)$sourceRecord['pid'], Connection::PARAM_INT))
            );
        }

        return (int)($queryBuilder->executeQuery()->fetchOne() ?: 0);
    }

    private function fetchRecord(string $table, int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    private function fetchSourcePages(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('uid', 'pid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }

    private function fetchContentElementsByPage(int $pageUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('*')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    private function fetchContentElementsByUid(array $uids): array
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
        if ($uids === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('*')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->orderBy('pid', 'ASC')
            ->addOrderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    private function groupPagesByParent(array $sourcePages): array
    {
        $children = [];
        foreach ($sourcePages as $page) {
            $children[(int)$page['pid']][] = (int)$page['uid'];
        }

        return $children;
    }

    private function expandSelectedPages(array $pageUids, array $subtreePageUids, array $childrenByPid): array
    {
        $expanded = $pageUids;
        foreach ($subtreePageUids as $pageUid) {
            $expanded[] = $pageUid;
            $queue = [$pageUid];
            while ($queue !== []) {
                $currentUid = array_shift($queue);
                foreach ($childrenByPid[$currentUid] ?? [] as $childUid) {
                    $expanded[] = $childUid;
                    $queue[] = $childUid;
                }
            }
        }

        return array_values(array_unique(array_filter(array_map('intval', $expanded))));
    }

    private function groupSelectedElementUidsByPage(array $selectedElements): array
    {
        $grouped = [];
        foreach ($selectedElements as $element) {
            $grouped[(int)$element['pid']][] = (int)$element['uid'];
        }

        return $grouped;
    }

    private function canEditPage(int $pageUid, BackendUserAuthentication $backendUser): bool
    {
        if ($backendUser->isAdmin()) {
            return true;
        }

        $permissionClause = $backendUser->getPagePermsClause(2);

        return BackendUtility::readPageAccess($pageUid, $permissionClause) !== false;
    }

    private function canEditContentOnPage(int $pageUid, BackendUserAuthentication $backendUser): bool
    {
        if ($backendUser->isAdmin()) {
            return true;
        }

        if (!$backendUser->check('tables_modify', 'tt_content')) {
            return false;
        }

        $permissionClause = $backendUser->getPagePermsClause(16);

        return BackendUtility::readPageAccess($pageUid, $permissionClause) !== false;
    }

    private function looksLikeHtml(string $value): bool
    {
        return $value !== strip_tags($value);
    }

    private function intList(mixed $value): array
    {
        if (!is_array($value)) {
            $value = [$value];
        }

        return array_values(array_unique(array_filter(array_map('intval', $value))));
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $backendUser instanceof BackendUserAuthentication ? $backendUser : null;
    }

    private function translate(string $key, array $arguments = []): string
    {
        return LocalizationUtility::translate($key, 'PplDeeplV3BatchTranslation', $arguments) ?? $key;
    }
}
