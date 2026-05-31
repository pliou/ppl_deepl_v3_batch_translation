<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service;

use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\BatchSelection;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\FieldOperation;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\PreflightPlan;
use Ppl\PplDeeplV3BatchTranslation\Domain\Enum\TranslationMode;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

final class BatchWorkspaceService
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly SelectionNormalizer $selectionNormalizer,
        private readonly SelectionBasketSummaryService $basketSummaryService,
        private readonly SelectionReviewService $selectionReviewService,
        private readonly BatchPageTreeService $pageTreeService,
        private readonly BatchPreflightService $preflightService,
        private readonly BatchPreviewService $previewService,
        private readonly BatchExecutionService $executionService,
        private readonly BatchJobLogger $jobLogger,
        private readonly BatchJobAccessGuard $jobAccessGuard,
        private readonly TranslationResourceOptionService $resourceOptionService,
        private readonly BatchResultViewModelService $resultViewModelService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(array $body, array $initialMessages = []): array
    {
        $siteOptions = $this->getSiteOptions();
        $body = $this->applyDefaults($body, $siteOptions);
        $rawAction = (string)($body['module_action'] ?? '');
        $action = $this->normalizeAction($rawAction);
        $previewScope = $this->previewScopeFromRequest($body, $rawAction);
        $stage = $this->normalizeStage((string)($body['workspace_stage'] ?? 'empty'));
        $confirmedJobUid = $this->confirmedJobUid($body);
        $resultJobUid = (int)($body['result_job_uid'] ?? 0);
        $makeTranslationsVisible = $this->makeTranslationsVisible($body);

        if ($action === 'clear_selection') {
            if ($this->rawJobStatus($confirmedJobUid) === 'previewed' && $this->jobAccessGuard->canAccessJob($confirmedJobUid)) {
                $this->jobLogger->discardJob($confirmedJobUid);
            }
            unset($body['selected_pages'], $body['selected_subtree_pages'], $body['selected_elements'], $body['excluded_pages'], $body['excluded_elements'], $body['confirmed_job_uid'], $body['confirmed_preview_job'], $body['result_job_uid']);
            $stage = 'scanned';
            $confirmedJobUid = 0;
            $resultJobUid = 0;
        }

        $selection = $this->selectionNormalizer->fromRequest($body);
        $site = $this->siteByIdentifier($selection->siteIdentifier);
        $rootPageUid = (int)($site['rootPageId'] ?? 0);
        $languageOptions = $this->getLanguageOptions($selection->siteIdentifier);
        $sourceLanguageCode = $this->languageCodeFromOptions($languageOptions, $selection->sourceLanguageId, true);
        $targetLanguageCode = $this->languageCodeFromOptions($languageOptions, $selection->targetLanguageId, false);
        $glossaryOptions = $this->resourceOptionService->getGlossaryOptionsForLanguagePair($sourceLanguageCode, $targetLanguageCode);
        $styleRuleOptions = $this->resourceOptionService->getStyleRuleOptionsForLanguage($targetLanguageCode);
        $messages = $initialMessages;
        $plan = $selection->isEmpty() ? null : $this->preflightService->buildPlan($selection);
        $confirmedJobStatus = $this->matchingJobStatus($confirmedJobUid, $selection);
        $executionResult = null;
        $activePageUid = (int)($body['active_page_uid'] ?? 0);
        $cachedPreviewPlan = $confirmedJobStatus === 'previewed' ? $this->jobLogger->loadPlan($confirmedJobUid) : null;

        if ($action === 'scan') {
            $stage = $selection->isEmpty() ? 'scanned' : 'selected_tree_scope';
            $messages[] = ['type' => 'success', 'text' => $this->translate('message.scanComplete')];
        } elseif ($action === 'restart_scan') {
            if ($this->rawJobStatus($confirmedJobUid) === 'previewed' && $this->jobAccessGuard->canAccessJob($confirmedJobUid)) {
                $this->jobLogger->discardJob($confirmedJobUid);
            }
            $confirmedJobUid = 0;
            $confirmedJobStatus = '';
            $cachedPreviewPlan = null;
            $stage = $selection->isEmpty() ? 'scanned' : 'selected_tree_scope';
            $messages[] = ['type' => 'success', 'text' => $this->translate('message.scanRestarted')];
        } elseif ($action === 'back_to_tree') {
            $stage = $selection->isEmpty() ? 'scanned' : 'selected_tree_scope';
        } elseif ($action === 'review_selection') {
            $stage = 'review_selection';
        } elseif ($action === 'open_page_preview') {
            $stage = 'page_preview';
            if ($previewScope['type'] === 'page' && $previewScope['uid'] > 0) {
                $activePageUid = $previewScope['uid'];
            }
        } elseif ($action === 'back_to_review') {
            $stage = 'review_selection';
        }

        if ($action === 'discard_preview') {
            if ($confirmedJobStatus === 'previewed' && $this->jobAccessGuard->canAccessJob($confirmedJobUid)) {
                $this->jobLogger->discardJob($confirmedJobUid);
                $messages[] = ['type' => 'success', 'text' => $this->translate('message.previewDiscarded')];
            } elseif ($confirmedJobUid > 0) {
                $messages[] = ['type' => 'error', 'text' => $this->jobAccessGuard->accessDeniedMessage()];
            }
            $confirmedJobUid = 0;
            $confirmedJobStatus = '';
            $cachedPreviewPlan = null;
            $stage = $selection->isEmpty() ? 'scanned' : 'review_selection';
        }

        if ($action === 'generate_preview' || $action === 'retranslate_selected') {
            if (!$plan instanceof \Ppl\PplDeeplV3BatchTranslation\Domain\Dto\PreflightPlan) {
                $messages[] = ['type' => 'error', 'text' => $this->translate('message.previewNeedsSelection')];
                $stage = 'scanned';
            } else {
                $preview = $this->previewService->buildPreview(
                    $plan,
                    $sourceLanguageCode,
                    $targetLanguageCode,
                    $action === 'retranslate_selected' ? null : $cachedPreviewPlan,
                    $action === 'retranslate_selected',
                    $previewScope['type'],
                    $previewScope['uid']
                );
                $plan = $preview['plan'];
                if ($preview['errors'] !== []) {
                    $failedJobUid = $this->jobLogger->createJobFromPlan($plan, 'preview_failed');
                    if ($this->rawJobStatus($confirmedJobUid) === 'previewed'
                        && $confirmedJobUid !== $failedJobUid
                        && $this->jobAccessGuard->canAccessJob($confirmedJobUid)
                    ) {
                        $this->jobLogger->discardJob($confirmedJobUid);
                    }
                    $stage = 'review_selection';
                    $confirmedJobUid = 0;
                    $confirmedJobStatus = '';
                    $cachedPreviewPlan = null;
                    $resultJobUid = 0;
                    $messages[] = ['type' => 'error', 'text' => sprintf($this->translate('message.previewFailedWithJob'), $failedJobUid)];
                    foreach ($preview['errors'] as $error) {
                        $messages[] = ['type' => 'error', 'text' => $error];
                    }
                } else {
                    $jobUid = $this->jobLogger->createJobFromPlan($plan, 'previewed');
                    $plan = $plan->withJobUid($jobUid);
                    if ($this->rawJobStatus($confirmedJobUid) === 'previewed' && $confirmedJobUid !== $jobUid && $this->jobAccessGuard->canAccessJob($confirmedJobUid)) {
                        $this->jobLogger->discardJob($confirmedJobUid);
                    }
                    $stage = 'preview_ready';
                    $confirmedJobUid = $jobUid;
                    $confirmedJobStatus = 'previewed';
                    $cachedPreviewPlan = $plan;
                    $resultJobUid = 0;
                    $messages[] = ['type' => 'success', 'text' => sprintf($this->translate('message.previewReady'), $jobUid)];
                }
            }
        }

        if ($action === 'write_translations') {
            if (!$this->canExecuteConfirmedJob($confirmedJobUid, $selection)) {
                $executionResult = [
                    'type' => 'error',
                    'text' => $this->translate('message.writeNeedsPreview'),
                    'counters' => [],
                ];
                $stage = $confirmedJobStatus === 'previewed' ? 'preview_ready' : 'review_selection';
            } else {
                $executionResult = $this->executionService->executePreviewJob($confirmedJobUid, $makeTranslationsVisible);
                $stage = 'results';
                $resultJobUid = $confirmedJobUid;
                $confirmedJobStatus = $this->rawJobStatus($confirmedJobUid);
                $cachedPreviewPlan = null;
            }
            $messages[] = [
                'type' => $executionResult['type'],
                'text' => $executionResult['text'],
            ];
        }

        if ($action === '' && in_array($stage, ['scanned', 'selected_tree_scope'], true) && !$selection->isEmpty()) {
            $stage = $confirmedJobStatus === 'previewed' ? 'preview_ready' : 'selected_tree_scope';
        }

        if ($cachedPreviewPlan instanceof PreflightPlan && !in_array($action, ['generate_preview', 'retranslate_selected'], true)) {
            $plan = $cachedPreviewPlan;
        }

        if ($activePageUid <= 0) {
            $activePageUid = $this->firstSelectedPageUid($selection) ?: $rootPageUid;
        }

        $tree = $this->pageTreeService->getTree(
            $rootPageUid,
            $selection->targetLanguageId,
            $selection,
            trim((string)($body['tree_search'] ?? '')),
            trim((string)($body['tree_status_filter'] ?? 'all'))
        );
        $pageDetails = $this->decoratePageDetailsWithPlan($this->markSelectedElements(
            $this->pageTreeService->getPageDetails($activePageUid, $selection->sourceLanguageId, $selection->targetLanguageId, $rootPageUid),
            $selection
        ), $plan);
        $selectionReview = $this->decorateSelectionReviewWithPlan(
            $this->selectionReviewService->buildReview($selection, $selection->targetLanguageId, $rootPageUid),
            $plan
        );
        $actionState = $this->buildActionState($selection, $confirmedJobUid, $confirmedJobStatus, $stage, $plan);
        $resultView = $stage === 'results' && $resultJobUid > 0
            ? $this->resultViewModelService->build($resultJobUid)
            : null;

        $workflowSteps = $this->buildWorkflowSteps($stage, $action, $selection, $plan, $confirmedJobStatus);
        $activeWorkflowStep = $this->activeWorkflowStep($workflowSteps);

        return [
            'messages' => $messages,
            'formData' => [
                'siteIdentifier' => $selection->siteIdentifier,
                'sourceLanguageId' => $selection->sourceLanguageId,
                'targetLanguageId' => $selection->targetLanguageId,
                'translationMode' => $selection->mode->value,
                'glossaryId' => (string)$selection->glossaryId,
                'styleRuleId' => $selection->styleRuleId,
                'customInstructions' => $selection->customInstructions,
                'treeSearch' => (string)($body['tree_search'] ?? ''),
                'treeStatusFilter' => (string)($body['tree_status_filter'] ?? 'all'),
                'activePageUid' => $activePageUid,
                'workspaceStage' => $stage,
                'confirmedJobUid' => $confirmedJobUid,
                'resultJobUid' => $resultJobUid,
                'previewScopeType' => $previewScope['type'],
                'previewScopeUid' => $previewScope['uid'],
                'makeTranslationsVisible' => $makeTranslationsVisible,
                'scanAction' => $stage === 'empty' ? 'scan' : 'restart_scan',
                'scanLabelKey' => $stage === 'empty' ? 'action.scan' : 'action.restartScan',
                'setupExpanded' => $stage === 'empty' || in_array($stage, ['scanned', 'selected_tree_scope'], true),
            ],
            'siteOptions' => $siteOptions,
            'languageOptions' => $languageOptions,
            'languageOptionsJson' => json_encode((object)$this->languageOptionsById($languageOptions), JSON_THROW_ON_ERROR),
            'siteSummary' => $this->pageTreeService->getSiteSummary((string)($site['label'] ?? $selection->siteIdentifier), $rootPageUid),
            'glossaryOptions' => $glossaryOptions,
            'glossaryOptionsByCombinationJson' => json_encode((object)$this->resourceOptionService->getGlossaryOptionsByCombination(), JSON_THROW_ON_ERROR),
            'styleRuleOptions' => $styleRuleOptions,
            'styleRuleOptionsJson' => json_encode((object)$this->resourceOptionService->getStyleRuleDisplayOptions(), JSON_THROW_ON_ERROR),
            'styleRuleOptionsByLanguageJson' => json_encode((object)$this->resourceOptionService->getStyleRuleOptionsByLanguage(), JSON_THROW_ON_ERROR),
            'translationModes' => $this->translationModeOptions(),
            'statusFilters' => $this->statusFilterOptions(),
            'setupSummary' => $this->buildSetupSummary(
                $languageOptions,
                $selection,
                $glossaryOptions,
                $styleRuleOptions,
                (string)($site['label'] ?? $selection->siteIdentifier),
                $confirmedJobStatus,
                $stage
            ),
            'jsLabels' => $this->jsLabels(),
            'selection' => $selection->toArray(),
            'basket' => $this->basketSummaryService->summarize($selection, $plan),
            'selectionReview' => $selectionReview,
            'actionState' => $actionState,
            'workflowSteps' => $workflowSteps,
            'activeWorkflowStep' => $activeWorkflowStep,
            'lastAction' => $action,
            'workspaceStage' => $stage,
            'showEmptyWorkspace' => $stage === 'empty',
            'showTreeWorkspace' => in_array($stage, ['scanned', 'selected_tree_scope'], true),
            'showReviewWorkspace' => in_array($stage, ['review_selection', 'preview_ready'], true),
            'showPagePreview' => $stage === 'page_preview',
            'showPreviewSummary' => $stage === 'preview_ready' && $plan instanceof PreflightPlan,
            'showResults' => $stage === 'results',
            'confirmedJobUid' => $confirmedJobUid,
            'confirmedJobStatus' => $confirmedJobStatus,
            'resultJobUid' => $resultJobUid,
            'tree' => $tree,
            'pageDetails' => $pageDetails,
            'preflight' => $plan?->toArray(),
            'executionResult' => $executionResult,
            'resultView' => $resultView,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $siteOptions
     * @return array<string, mixed>
     */
    private function applyDefaults(array $body, array $siteOptions): array
    {
        $siteIdentifier = trim((string)($body['site_identifier'] ?? ''));
        if ($siteIdentifier === '' && $siteOptions !== []) {
            $siteIdentifier = (string)$siteOptions[0]['identifier'];
            $body['site_identifier'] = $siteIdentifier;
        }

        $languageOptions = $this->getLanguageOptions($siteIdentifier);
        if (!isset($body['source_language_id'])) {
            $body['source_language_id'] = 0;
        }
        if (!isset($body['target_language_id']) || (int)$body['target_language_id'] === (int)$body['source_language_id']) {
            foreach ($languageOptions as $language) {
                if ((int)$language['id'] !== (int)$body['source_language_id']) {
                    $body['target_language_id'] = (int)$language['id'];
                    break;
                }
            }
        }
        if (!isset($body['translation_mode'])) {
            $body['translation_mode'] = TranslationMode::TranslateMissingOnly->value;
        }

        $sourceLanguageCode = $this->languageCodeFromOptions($languageOptions, (int)$body['source_language_id'], true);
        $targetLanguageCode = $this->languageCodeFromOptions($languageOptions, (int)$body['target_language_id'], false);
        $glossaryId = trim((string)($body['glossary_id'] ?? ''));
        if ($glossaryId !== '' && !$this->resourceOptionService->isGlossaryAvailableForLanguagePair($glossaryId, $sourceLanguageCode, $targetLanguageCode)) {
            $body['glossary_id'] = '';
        }

        $styleRuleId = trim((string)($body['style_rule_id'] ?? ''));
        if ($styleRuleId !== '' && !$this->resourceOptionService->isStyleRuleAvailableForLanguage($styleRuleId, $targetLanguageCode)) {
            $body['style_rule_id'] = '';
        }

        return $body;
    }

    private function normalizeAction(string $action): string
    {
        if (str_starts_with($action, 'open_page_preview:')) {
            return 'open_page_preview';
        }
        if (str_starts_with($action, 'generate_preview:')) {
            return 'generate_preview';
        }

        return match ($action) {
            'preview' => 'generate_preview',
            'execute' => 'write_translations',
            'rescan' => 'restart_scan',
            'preflight' => 'review_selection',
            default => $action,
        };
    }

    private function normalizeStage(string $stage): string
    {
        return match ($stage) {
            'select' => 'scanned',
            'review', 'preflight' => 'review_selection',
            'preview' => 'preview_ready',
            'empty', 'scanned', 'selected_tree_scope', 'review_selection', 'page_preview', 'preview_ready', 'writing', 'results' => $stage,
            default => 'empty',
        };
    }

    private function makeTranslationsVisible(array $body): bool
    {
        $value = $body['make_translations_visible'] ?? '1';
        if (is_array($value)) {
            $value = end($value);
        }

        return !in_array(strtolower(trim((string)$value)), ['0', 'false', 'off', 'hidden', 'no'], true);
    }

    /**
     * @return array{type: string, uid: int}
     */
    private function previewScopeFromRequest(array $body, string $rawAction): array
    {
        $scopeType = (string)($body['preview_scope_type'] ?? 'batch');
        $scopeUid = max(0, (int)($body['preview_scope_uid'] ?? 0));

        if (preg_match('/^open_page_preview:(\d+)$/', $rawAction, $matches)) {
            return ['type' => 'page', 'uid' => (int)$matches[1]];
        }
        if (preg_match('/^generate_preview:(page|element):(\d+)(?::\d+)?$/', $rawAction, $matches)) {
            return ['type' => $matches[1], 'uid' => (int)$matches[2]];
        }
        if (!in_array($scopeType, ['batch', 'page', 'element'], true)) {
            $scopeType = 'batch';
            $scopeUid = 0;
        }

        return [
            'type' => $scopeType,
            'uid' => $scopeType === 'batch' ? 0 : $scopeUid,
        ];
    }

    /**
     * @return array<int, array{identifier: string, label: string, rootPageId: int}>
     */
    private function getSiteOptions(): array
    {
        try {
            $sites = $this->siteFinder->getAllSites();
        } catch (\Throwable) {
            $sites = [];
        }

        $options = [];
        foreach ($sites as $site) {
            $identifier = method_exists($site, 'getIdentifier') ? (string)$site->getIdentifier() : '';
            if ($identifier === '') {
                continue;
            }
            $configuration = method_exists($site, 'getConfiguration') ? (array)$site->getConfiguration() : [];
            $options[] = [
                'identifier' => $identifier,
                'label' => (string)($configuration['websiteTitle'] ?? $identifier),
                'rootPageId' => method_exists($site, 'getRootPageId') ? (int)$site->getRootPageId() : 0,
            ];
        }

        if ($options === []) {
            $options[] = [
                'identifier' => 'default',
                'label' => 'Default site',
                'rootPageId' => 0,
            ];
        }

        return $options;
    }

    /**
     * @return array<int, array{id: int, title: string, locale: string, deeplSource: string, deeplTarget: string}>
     */
    private function getLanguageOptions(string $siteIdentifier): array
    {
        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
            $languages = method_exists($site, 'getLanguages') ? $site->getLanguages() : [];
        } catch (\Throwable) {
            $languages = [];
        }

        $options = [];
        foreach ($languages as $languageId => $language) {
            $id = $this->languageIdFromSiteLanguageEntry($languageId, $language);
            $title = method_exists($language, 'getTitle') ? (string)$language->getTitle() : 'Language ' . $id;
            $locale = method_exists($language, 'getLocale') ? $this->localeToString($language->getLocale()) : '';
            $options[] = [
                'id' => $id,
                'title' => $title,
                'locale' => $locale,
                'deeplSource' => $this->normalizeSiteLanguage($locale, true),
                'deeplTarget' => $this->normalizeSiteLanguage($locale, false),
            ];
        }

        if ($options === []) {
            $options = [
                ['id' => 0, 'title' => 'English', 'locale' => 'en-US', 'deeplSource' => 'EN', 'deeplTarget' => 'EN-US'],
                ['id' => 1, 'title' => 'German', 'locale' => 'de-DE', 'deeplSource' => 'DE', 'deeplTarget' => 'DE'],
            ];
        }

        return $options;
    }

    private function languageIdFromSiteLanguageEntry(int|string $languageId, mixed $language): int
    {
        if (is_int($languageId) || ctype_digit((string)$languageId)) {
            return (int)$languageId;
        }

        if (is_object($language) && method_exists($language, 'toArray')) {
            $languageData = $language->toArray();
            if (is_array($languageData) && isset($languageData['languageId'])) {
                return (int)$languageData['languageId'];
            }
        }

        return 0;
    }

    private function languageCode(string $siteIdentifier, int $languageId, bool $source): string
    {
        return $this->languageCodeFromOptions($this->getLanguageOptions($siteIdentifier), $languageId, $source);
    }

    /**
     * @param array<int, array{id: int, title: string, locale: string, deeplSource: string, deeplTarget: string}> $languageOptions
     */
    private function languageCodeFromOptions(array $languageOptions, int $languageId, bool $source): string
    {
        foreach ($languageOptions as $language) {
            if ((int)$language['id'] === $languageId) {
                return (string)($source ? $language['deeplSource'] : $language['deeplTarget']);
            }
        }

        return $source ? 'EN' : 'DE';
    }

    private function normalizeSiteLanguage(string $locale, bool $source): string
    {
        $locale = trim($locale);
        if ($locale === '') {
            return $source ? TranslationResourceOptionService::DEFAULT_SOURCE_LANGUAGE : TranslationResourceOptionService::DEFAULT_TARGET_LANGUAGE;
        }

        $language = $source
            ? $this->resourceOptionService->normalizeSourceLanguage($locale)
            : $this->resourceOptionService->normalizeTargetLanguage($locale);

        return $language !== ''
            ? $language
            : ($source ? TranslationResourceOptionService::DEFAULT_SOURCE_LANGUAGE : TranslationResourceOptionService::DEFAULT_TARGET_LANGUAGE);
    }

    /**
     * @param array<int, array{id: int, title: string, locale: string, deeplSource: string, deeplTarget: string}> $languageOptions
     * @return array<int, array<string, string|int>>
     */
    private function languageOptionsById(array $languageOptions): array
    {
        $optionsById = [];
        foreach ($languageOptions as $language) {
            $id = (int)$language['id'];
            $optionsById[$id] = $language;
        }

        return $optionsById;
    }

    private function localeToString(mixed $locale): string
    {
        if (is_object($locale) && method_exists($locale, 'getName')) {
            return (string)$locale->getName();
        }
        if (is_object($locale) && method_exists($locale, '__toString')) {
            return (string)$locale;
        }
        if (is_scalar($locale)) {
            return (string)$locale;
        }

        return '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function siteByIdentifier(string $siteIdentifier): ?array
    {
        foreach ($this->getSiteOptions() as $site) {
            if ($site['identifier'] === $siteIdentifier) {
                return $site;
            }
        }

        return null;
    }

    private function firstSelectedPageUid(BatchSelection $selection): int
    {
        if ($selection->selectedPages !== []) {
            return $selection->selectedPages[0]->pageUid;
        }
        if ($selection->selectedSubtrees !== []) {
            return $selection->selectedSubtrees[0]->rootPageUid;
        }

        return 0;
    }

    private function confirmedJobUid(array $body): int
    {
        $value = $body['confirmed_job_uid'] ?? ($body['confirmed_preview_job'] ?? 0);

        return max(0, (int)$value);
    }

    private function matchingJobStatus(int $jobUid, BatchSelection $selection): string
    {
        $loadedJob = $this->jobLogger->loadJob($jobUid);
        if ($loadedJob === null) {
            return '';
        }

        $job = $loadedJob['job'];
        if (!$this->jobAccessGuard->canAccessStoredJob($job)) {
            return '';
        }
        if ((string)($job['site_identifier'] ?? '') !== $selection->siteIdentifier
            || (int)($job['source_language_id'] ?? -1) !== $selection->sourceLanguageId
            || (int)($job['target_language_id'] ?? -1) !== $selection->targetLanguageId
            || (string)($job['translation_mode'] ?? '') !== $selection->mode->value
        ) {
            return '';
        }

        try {
            $jobSelection = json_decode((string)($job['selected_scope_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return '';
        }

        if ($jobSelection !== $selection->toArray()) {
            return '';
        }

        return (string)($job['status'] ?? '');
    }

    private function rawJobStatus(int $jobUid): string
    {
        $loadedJob = $this->jobLogger->loadJob($jobUid);
        if ($loadedJob === null) {
            return '';
        }

        return (string)($loadedJob['job']['status'] ?? '');
    }

    private function canExecuteConfirmedJob(int $jobUid, BatchSelection $selection): bool
    {
        return $this->matchingJobStatus($jobUid, $selection) === 'previewed';
    }

    /**
     * @param array<string, mixed> $selectionReview
     * @return array<string, mixed>
     */
    private function decorateSelectionReviewWithPlan(array $selectionReview, ?PreflightPlan $plan): array
    {
        $operationsByRecord = $this->previewOperationsByRecord($plan);
        $pendingOperationsByRecord = $this->pendingOperationsByRecord($plan);
        foreach (is_array($selectionReview['groups'] ?? null) ? $selectionReview['groups'] : [] as $pageIndex => $page) {
            $pageKey = 'pages:' . (int)($page['uid'] ?? 0);
            $pageOperations = $operationsByRecord[$pageKey] ?? [];
            $pagePendingOperations = $pendingOperationsByRecord[$pageKey] ?? [];
            $selectionReview['groups'][$pageIndex]['previewOperations'] = $pageOperations;
            $selectionReview['groups'][$pageIndex]['hasPreview'] = $pageOperations !== [];
            $selectionReview['groups'][$pageIndex]['hasPendingOperations'] = $pagePendingOperations !== [];
            $selectionReview['groups'][$pageIndex]['previewActionIsRetranslation'] = $this->operationsContainOverwrite($pagePendingOperations);

            foreach (is_array($page['elements'] ?? null) ? $page['elements'] : [] as $elementIndex => $element) {
                $elementKey = 'tt_content:' . (int)($element['uid'] ?? 0);
                $elementOperations = $operationsByRecord[$elementKey] ?? [];
                $elementPendingOperations = $pendingOperationsByRecord[$elementKey] ?? [];
                $selectionReview['groups'][$pageIndex]['elements'][$elementIndex]['previewOperations'] = $elementOperations;
                $selectionReview['groups'][$pageIndex]['elements'][$elementIndex]['hasPreview'] = $elementOperations !== [];
                $selectionReview['groups'][$pageIndex]['elements'][$elementIndex]['hasPendingOperations'] = $elementPendingOperations !== [];
                $selectionReview['groups'][$pageIndex]['elements'][$elementIndex]['previewActionIsRetranslation'] = $this->operationsContainOverwrite($elementPendingOperations);
            }
        }

        return $selectionReview;
    }

    /**
     * @param array<string, mixed> $pageDetails
     * @return array<string, mixed>
     */
    private function decoratePageDetailsWithPlan(array $pageDetails, ?PreflightPlan $plan): array
    {
        $operationsByRecord = $this->previewOperationsByRecord($plan);
        $pendingOperationsByRecord = $this->pendingOperationsByRecord($plan);
        $operationByRecordAndField = $this->previewOperationsByRecordAndField($plan);
        $pageUid = (int)($pageDetails['page']['uid'] ?? 0);
        $pageOperations = $operationsByRecord['pages:' . $pageUid] ?? [];
        $pagePendingOperations = $pendingOperationsByRecord['pages:' . $pageUid] ?? [];
        if (is_array($pageDetails['page'] ?? null)) {
            $pageDetails['page']['hasPreview'] = $pageOperations !== [];
            $pageDetails['page']['previewOperations'] = $pageOperations;
            $pageDetails['page']['hasPendingOperations'] = $pagePendingOperations !== [];
            $pageDetails['page']['previewActionIsRetranslation'] = $this->operationsContainOverwrite($pagePendingOperations);
        }

        foreach (is_array($pageDetails['pageFields'] ?? null) ? $pageDetails['pageFields'] : [] as $fieldIndex => $field) {
            $operation = $operationByRecordAndField['pages:' . $pageUid . ':' . (string)($field['field'] ?? '')] ?? null;
            $pageDetails['pageFields'][$fieldIndex]['hasProposal'] = is_array($operation);
            $pageDetails['pageFields'][$fieldIndex]['proposalPreview'] = is_array($operation) ? (string)$operation['translatedValue'] : '';
            $pageDetails['pageFields'][$fieldIndex]['actionLabel'] = is_array($operation) ? (string)$operation['actionLabel'] : '';
        }

        foreach (is_array($pageDetails['elements'] ?? null) ? $pageDetails['elements'] : [] as $elementIndex => $element) {
            $elementKey = 'tt_content:' . (int)($element['uid'] ?? 0);
            $elementOperations = $operationsByRecord[$elementKey] ?? [];
            $elementPendingOperations = $pendingOperationsByRecord[$elementKey] ?? [];
            $pageDetails['elements'][$elementIndex]['hasPreview'] = $elementOperations !== [];
            $pageDetails['elements'][$elementIndex]['previewOperations'] = $elementOperations;
            $pageDetails['elements'][$elementIndex]['hasPendingOperations'] = $elementPendingOperations !== [];
            $pageDetails['elements'][$elementIndex]['previewActionIsRetranslation'] = $this->operationsContainOverwrite($elementPendingOperations);
        }

        return $pageDetails;
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function pendingOperationsByRecord(?PreflightPlan $plan): array
    {
        if (!$plan instanceof PreflightPlan) {
            return [];
        }

        $operationsByRecord = [];
        foreach ($plan->items as $item) {
            foreach ($item->fieldOperations as $operation) {
                $operationsByRecord[$operation->table . ':' . $item->effectiveBaseUid()][] = $this->operationView($operation);
            }
        }

        return $operationsByRecord;
    }

    /**
     * @param array<int, array<string, mixed>> $operations
     */
    private function operationsContainOverwrite(array $operations): bool
    {
        foreach ($operations as $operation) {
            if ((string)($operation['writeAction'] ?? '') === 'overwrite') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function previewOperationsByRecord(?PreflightPlan $plan): array
    {
        if (!$plan instanceof PreflightPlan) {
            return [];
        }

        $operationsByRecord = [];
        foreach ($plan->items as $item) {
            foreach ($item->fieldOperations as $operation) {
                if (trim($operation->translatedValue) === '') {
                    continue;
                }
                $operationsByRecord[$operation->table . ':' . $item->effectiveBaseUid()][] = $this->operationView($operation);
            }
        }

        return $operationsByRecord;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function previewOperationsByRecordAndField(?PreflightPlan $plan): array
    {
        if (!$plan instanceof PreflightPlan) {
            return [];
        }

        $operationsByRecordAndField = [];
        foreach ($plan->items as $item) {
            foreach ($item->fieldOperations as $operation) {
                if (trim($operation->translatedValue) === '') {
                    continue;
                }
                $operationsByRecordAndField[$operation->table . ':' . $item->effectiveBaseUid() . ':' . $operation->field] = $this->operationView($operation);
            }
        }

        return $operationsByRecordAndField;
    }

    /**
     * @return array<string, mixed>
     */
    private function operationView(FieldOperation $operation): array
    {
        return array_merge($operation->toArray(), [
            'actionLabel' => $this->operationActionLabel($operation->writeAction),
            'hasCurrent' => trim($operation->targetValue) !== '',
            'hasProposal' => trim($operation->translatedValue) !== '',
        ]);
    }

    private function operationActionLabel(string $writeAction): string
    {
        return match ($writeAction) {
            'fill_empty' => $this->translate('label.operationFillEmpty'),
            'overwrite' => $this->translate('label.operationOverwrite'),
            default => $this->translate('label.operationWillWrite'),
        };
    }

    private function planHasExistingTargetsOrOverwrites(?PreflightPlan $plan): bool
    {
        if (!$plan instanceof PreflightPlan) {
            return false;
        }

        foreach ($plan->items as $item) {
            if ($item->targetUid > 0) {
                return true;
            }
            foreach ($item->fieldOperations as $operation) {
                if ($operation->writeAction === 'overwrite' || trim($operation->targetValue) !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    private function planHasExecutableWork(?PreflightPlan $plan): bool
    {
        if (!$plan instanceof PreflightPlan) {
            return false;
        }

        foreach ($plan->items as $item) {
            if ($item->isBlocked() || $item->recordAction === 'skip') {
                continue;
            }
            if (!$plan->selection->mode->translatesText()) {
                return true;
            }
            foreach ($item->writableFieldOperations() as $operation) {
                if (trim($operation->translatedValue) !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, bool|string>
     */
    private function buildActionState(BatchSelection $selection, int $confirmedJobUid, string $confirmedJobStatus, string $stage, ?PreflightPlan $plan = null): array
    {
        $hasSelection = !$selection->isEmpty();
        $hasPreview = $confirmedJobUid > 0 && $confirmedJobStatus === 'previewed';
        $canShowSelectedItems = $hasSelection && in_array($stage, ['scanned', 'selected_tree_scope'], true);
        $canGeneratePreview = $hasSelection && !$hasPreview && $stage === 'review_selection';
        $canWrite = $hasPreview && $stage === 'preview_ready' && !$selection->mode->isPreviewOnly() && $this->planHasExecutableWork($plan);
        $canRetranslate = $hasPreview && $selection->mode->translatesText() && $this->planHasExistingTargetsOrOverwrites($plan);
        $primaryAction = 'scan';

        if ($canShowSelectedItems) {
            $primaryAction = 'review_selection';
        } elseif ($stage === 'review_selection' && $canGeneratePreview) {
            $primaryAction = 'generate_preview';
        } elseif ($stage === 'preview_ready' && $canWrite) {
            $primaryAction = 'write_translations';
        } elseif ($stage === 'results') {
            $primaryAction = 'scan';
        }

        return [
            'hasSelection' => $hasSelection,
            'hasPreflight' => $hasSelection,
            'hasPreview' => $hasPreview,
            'canReview' => $canShowSelectedItems,
            'canShowSelectedItems' => $canShowSelectedItems,
            'canBackToTree' => in_array($stage, ['review_selection', 'page_preview'], true),
            'canPreflight' => false,
            'canPreview' => $canGeneratePreview,
            'canGeneratePreview' => $canGeneratePreview,
            'canExecute' => $canWrite,
            'canWrite' => $canWrite,
            'canRetranslate' => $canRetranslate && $stage === 'preview_ready',
            'canDiscardPreview' => $hasPreview && $stage === 'preview_ready',
            'canClearSelection' => $hasSelection && $stage !== 'results',
            'canStartNewScan' => $stage === 'results',
            'canExportResult' => $stage === 'results',
            'requiresPreview' => $selection->mode->translatesText(),
            'primaryAction' => $primaryAction,
        ];
    }

    /**
     * @param array<int, array{id: int, title: string, locale: string, deeplSource: string, deeplTarget: string}> $languageOptions
     * @param array<string, string> $glossaryOptions
     * @param array<string, string> $styleRuleOptions
     * @return array<string, string>
     */
    private function buildSetupSummary(array $languageOptions, BatchSelection $selection, array $glossaryOptions, array $styleRuleOptions, string $siteLabel, string $confirmedJobStatus, string $stage): array
    {
        $source = $this->languageDisplayLabel($languageOptions, $selection->sourceLanguageId, true);
        $target = $this->languageDisplayLabel($languageOptions, $selection->targetLanguageId, false);
        $glossary = (string)$selection->glossaryId !== ''
            ? (string)($glossaryOptions[(string)$selection->glossaryId] ?? $this->translate('label.noApprovedGlossary'))
            : $this->translate('label.noApprovedGlossary');
        $styleRule = $selection->styleRuleId !== ''
            ? (string)($styleRuleOptions[$selection->styleRuleId] ?? $this->translate('label.noApprovedStyleRule'))
            : $this->translate('label.noApprovedStyleRule');

        $writeState = match (true) {
            $stage === 'results' => $this->translate('label.writeCompleted'),
            $confirmedJobStatus === 'previewed' => $this->translate('label.readyToWriteConfirmedTranslations'),
            default => $this->translate('label.noWritesYet'),
        };

        return [
            'source' => $source,
            'target' => $target,
            'pair' => $source . ' -> ' . $target,
            'glossary' => $glossary,
            'styleRule' => $styleRule,
            'site' => $siteLabel,
            'writeState' => $writeState,
        ];
    }

    /**
     * @param array<int, array{id: int, title: string, locale: string, deeplSource: string, deeplTarget: string}> $languageOptions
     */
    private function languageDisplayLabel(array $languageOptions, int $languageId, bool $source): string
    {
        foreach ($languageOptions as $language) {
            if ((int)$language['id'] === $languageId) {
                $code = (string)($source ? $language['deeplSource'] : $language['deeplTarget']);
                return trim((string)$language['title']) . ($code !== '' ? ' (' . $code . ')' : '');
            }
        }

        return $source ? $this->translate('label.sourceLanguageFallback') : $this->translate('label.targetLanguageFallback');
    }

    /**
     * @return array<string, string>
     */
    private function translationModeOptions(): array
    {
        return [
            TranslationMode::TranslateMissingOnly->value => $this->translate('mode.translateMissingOnly'),
            TranslationMode::RetranslateSelected->value => $this->translate('mode.retranslateSelected'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function statusFilterOptions(): array
    {
        return [
            'all' => $this->translate('filter.all'),
            'missing' => $this->translate('filter.missing'),
            'partial' => $this->translate('filter.partial'),
            'translated' => $this->translate('filter.translated'),
            'translated_but_empty_fields' => $this->translate('filter.emptyFields'),
            'hidden' => $this->translate('filter.hidden'),
            'blocked' => $this->translate('filter.blocked'),
            'source_missing' => $this->translate('filter.sourceMissing'),
            'selected' => $this->translate('filter.selected'),
            'has_content' => $this->translate('filter.hasContent'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function jsLabels(): array
    {
        return [
            'selectedPagesTitle' => $this->translate('label.selectedPagesTitle'),
            'selectedBranchesTitle' => $this->translate('label.selectedBranchesTitle'),
            'selectedElementsTitle' => $this->translate('label.selectedElementsTitle'),
            'removeSelection' => $this->translate('action.removeSelection'),
            'removeExclusion' => $this->translate('action.removeExclusion'),
            'selectionUpdated' => $this->translate('message.selectionUpdated'),
            'previewFailed' => $this->translate('message.previewFailed'),
            'writingTranslations' => $this->translate('message.writingTranslations'),
        ];
    }

    /**
     * @return array<int, array{number: int, labelKey: string, hintKey: string, state: string, isActive: bool, isDone: bool, ariaLabel: string}>
     */
    private function buildWorkflowSteps(string $stage, string $action, BatchSelection $selection, mixed $plan, string $confirmedJobStatus): array
    {
        $hasSelection = !$selection->isEmpty();
        $hasPreview = $confirmedJobStatus === 'previewed';
        $executed = $stage === 'results' && $action === 'write_translations';

        $steps = [
            'scope' => 'done',
            'scan' => $stage === 'empty' ? 'active' : 'done',
            'select' => in_array($stage, ['scanned', 'selected_tree_scope'], true) ? 'active' : ($hasSelection ? 'done' : 'pending'),
            'review' => in_array($stage, ['review_selection', 'page_preview'], true) ? 'active' : (in_array($stage, ['preview_ready', 'writing', 'results'], true) ? 'done' : ($hasSelection ? 'pending' : 'pending')),
            'translationPreview' => $stage === 'preview_ready' ? 'active' : ($hasPreview ? 'done' : ($hasSelection ? 'pending' : 'pending')),
            'write' => $stage === 'writing' ? 'active' : ($executed ? 'done' : ($hasPreview ? 'pending' : 'pending')),
            'results' => $stage === 'results' ? 'active' : 'pending',
        ];

        $labels = [
            'scope' => ['workflow.scope', 'workflow.scope.hint'],
            'scan' => ['workflow.scan', 'workflow.scan.hint'],
            'select' => ['workflow.select', 'workflow.select.hint'],
            'review' => ['workflow.review', 'workflow.review.hint'],
            'translationPreview' => ['workflow.translationPreview', 'workflow.translationPreview.hint'],
            'write' => ['workflow.write', 'workflow.write.hint'],
            'results' => ['workflow.results', 'workflow.results.hint'],
        ];

        $workflowSteps = [];
        $number = 1;
        foreach ($labels as $key => $labelKeys) {
            $state = $steps[$key];
            $label = $this->translate($labelKeys[0]);
            $stateLabel = match ($state) {
                'active' => $this->translate('workflow.state.current'),
                'done' => $this->translate('workflow.state.completed'),
                default => $this->translate('workflow.state.upcoming'),
            };
            $workflowSteps[] = [
                'number' => $number++,
                'labelKey' => 'LLL:EXT:ppl_deepl_v3_batch_translation/Resources/Private/Language/locallang.xlf:' . $labelKeys[0],
                'hintKey' => 'LLL:EXT:ppl_deepl_v3_batch_translation/Resources/Private/Language/locallang.xlf:' . $labelKeys[1],
                'state' => $state,
                'isActive' => $state === 'active',
                'isDone' => $state === 'done',
                'ariaLabel' => sprintf($this->translate('workflow.stepAria'), $number - 1, $label, $stateLabel),
            ];
        }

        return $workflowSteps;
    }

    /**
     * @param array<int, array<string, mixed>> $workflowSteps
     * @return array<string, mixed>
     */
    private function activeWorkflowStep(array $workflowSteps): array
    {
        foreach ($workflowSteps as $step) {
            if (!empty($step['isActive'])) {
                return $step;
            }
        }

        return $workflowSteps[0] ?? [];
    }

    /**
     * @param array<string, mixed> $pageDetails
     * @return array<string, mixed>
     */
    private function markSelectedElements(array $pageDetails, BatchSelection $selection): array
    {
        $selected = array_fill_keys(array_map(static fn($item): int => $item->contentUid, $selection->selectedElements), true);
        $excluded = array_fill_keys($selection->excludedElementUids, true);
        if (!is_array($pageDetails['elements'] ?? null)) {
            return $pageDetails;
        }

        foreach ($pageDetails['elements'] as $index => $element) {
            $contentUid = (int)($element['uid'] ?? 0);
            $pageDetails['elements'][$index]['selected'] = isset($selected[$contentUid]);
            $pageDetails['elements'][$index]['excluded'] = isset($excluded[$contentUid]);
        }

        return $pageDetails;
    }

    private function translate(string $key): string
    {
        return LocalizationUtility::translate($key, 'ppl_deepl_v3_batch_translation') ?? $key;
    }
}
