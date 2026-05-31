<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service\Smoke;

use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\BatchSelection;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\ElementSelection;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\PageSelection;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\PreflightPlan;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\SubtreeSelection;
use Ppl\PplDeeplV3BatchTranslation\Domain\Enum\TranslationMode;
use Ppl\PplDeeplV3BatchTranslation\Service\BatchExecutionService;
use Ppl\PplDeeplV3BatchTranslation\Service\BatchJobLogger;
use Ppl\PplDeeplV3BatchTranslation\Service\BatchPageTreeService;
use Ppl\PplDeeplV3BatchTranslation\Service\BatchPreflightService;
use Ppl\PplDeeplV3BatchTranslation\Service\BatchPreviewService;
use Ppl\PplDeeplV3BatchTranslation\Service\BatchResultViewModelService;
use Ppl\PplDeeplV3BatchTranslation\Service\SelectionBasketSummaryService;
use Ppl\PplDeeplV3BatchTranslation\Service\SelectionNormalizer;
use Ppl\PplDeeplV3BatchTranslation\Service\SelectionReviewService;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class SmokeMatrixRunner
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SmokeContext $context,
        private readonly SmokeFixtureService $fixtureService,
        private readonly BatchPreflightService $preflightService,
        private readonly BatchPreviewService $previewService,
        private readonly BatchJobLogger $jobLogger,
        private readonly BatchExecutionService $executionService,
        private readonly BatchResultViewModelService $resultViewModelService,
        private readonly SelectionReviewService $selectionReviewService,
        private readonly SelectionBasketSummaryService $basketSummaryService,
        private readonly SelectionNormalizer $selectionNormalizer,
        private readonly BatchPageTreeService $pageTreeService
    ) {}

    /**
     * @param array<string, mixed> $fixture
     * @return array<string, mixed>
     */
    public function runMatrix(array $fixture, string $artifactRoot, ?string $onlyCase = null): array
    {
        $previousSmokeEnvironment = $this->enableSmokeEnvironment($artifactRoot);
        try {
            $this->context->activate($artifactRoot);
            $this->writeJson($artifactRoot . '/fake-deepl-calls.json', []);
            foreach (['reports', 'db-before', 'db-after', 'screenshots'] as $directory) {
                if (!is_dir($artifactRoot . '/' . $directory)) {
                    mkdir($artifactRoot . '/' . $directory, 0775, true);
                }
            }

            $summary = [
                'artifactRoot' => $artifactRoot,
                'startedAt' => date(DATE_ATOM),
                'cases' => [],
            ];

            foreach ($this->caseIds() as $caseId) {
                if ($onlyCase !== null && $onlyCase !== $caseId) {
                    continue;
                }
                $this->fixtureService->restoreTargetState($fixture, $artifactRoot);
                $GLOBALS['BE_USER'] = $caseId === 'BT-SMOKE-012'
                    ? $this->fixtureService->limitedBackendUser()
                    : $this->fixtureService->adminBackendUser();

                $beforeCalls = $this->fakeCallCount($artifactRoot);
                $beforeSnapshot = $this->snapshot($fixture);
                $this->writeJson($artifactRoot . '/db-before/' . $caseId . '.json', $beforeSnapshot);
                $report = $this->runCase($caseId, $fixture, $artifactRoot, $beforeCalls);
                $report['dbBefore'] = 'db-before/' . $caseId . '.json';
                $report['dbAfter'] = 'db-after/' . $caseId . '.json';
                $afterSnapshot = $this->snapshot($fixture);
                $this->writeJson($artifactRoot . '/db-after/' . $caseId . '.json', $afterSnapshot);
                $this->writeJson($artifactRoot . '/reports/' . $caseId . '.json', $report);
                $summary['cases'][] = [
                    'caseId' => $caseId,
                    'status' => $report['status'],
                    'assertions' => $report['assertions'],
                    'report' => 'reports/' . $caseId . '.json',
                ];
            }

            $summary['finishedAt'] = date(DATE_ATOM);
            $this->writeSummary($artifactRoot . '/summary.md', $summary);

            return $summary;
        } finally {
            $this->context->deactivate();
            $this->restoreSmokeEnvironment($previousSmokeEnvironment);
        }
    }

    /**
     * @return array<string, string|false>
     */
    private function enableSmokeEnvironment(string $artifactRoot): array
    {
        $previous = [
            'PPL_BATCH_TRANSLATION_SMOKE' => getenv('PPL_BATCH_TRANSLATION_SMOKE'),
            'PPL_BATCH_TRANSLATION_SMOKE_ARTIFACT_ROOT' => getenv('PPL_BATCH_TRANSLATION_SMOKE_ARTIFACT_ROOT'),
        ];

        putenv('PPL_BATCH_TRANSLATION_SMOKE=1');
        putenv('PPL_BATCH_TRANSLATION_SMOKE_ARTIFACT_ROOT=' . $artifactRoot);

        return $previous;
    }

    /**
     * @param array<string, string|false> $previous
     */
    private function restoreSmokeEnvironment(array $previous): void
    {
        foreach ($previous as $name => $value) {
            if ($value === false) {
                putenv($name);
                continue;
            }

            putenv($name . '=' . $value);
        }
    }

    /**
     * @return string[]
     */
    private function caseIds(): array
    {
        return array_map(static fn(int $number): string => sprintf('BT-SMOKE-%03d', $number), range(1, 29));
    }

    /**
     * @param array<string, mixed> $fixture
     * @return array<string, mixed>
     */
    private function runCase(string $caseId, array $fixture, string $artifactRoot, int $beforeCalls): array
    {
        $assertions = [];
        $messages = [];
        $selection = null;
        $plan = null;
        $jobUid = 0;
        $execution = null;
        $resultView = null;

        try {
            switch ($caseId) {
                case 'BT-SMOKE-001':
                    $tree = $this->pageTreeService->getTree((int)$fixture['pages']['root'], 1, $this->selection($fixture));
                    $assertions[] = $this->assertTrue($tree !== [], 'scan returns fixture page tree');
                    $assertions[] = $this->assertSame($beforeCalls, $this->fakeCallCount($artifactRoot), 'scan makes no Fake DeepL calls');
                    break;
                case 'BT-SMOKE-002':
                    $selection = $this->selection($fixture, elements: [(int)$fixture['content'][(string)$fixture['pages']['batchTests']]['e4']]);
                    [$plan, $jobUid, $execution, $resultView] = $this->previewAndWrite($selection);
                    $targetContentUid = $this->targetContentUid((int)$fixture['content'][(string)$fixture['pages']['batchTests']]['e4']);
                    $assertions[] = $this->assertTrue($targetContentUid > 0, 'selected element target exists');
                    $assertions[] = $this->assertSame(0, (int)($this->contentRecord($targetContentUid)['hidden'] ?? 1), 'written element translation is visible');
                    $assertions[] = $this->assertSame(0, $this->targetPageUid((int)$fixture['pages']['batchTests']), 'element-only does not create page target');
                    $assertions[] = $this->assertSame(0, $this->targetContentUid((int)$fixture['content'][(string)$fixture['pages']['batchTests']]['e1']), 'element-only does not create sibling target');
                    $tree = $this->pageTreeService->getTree((int)$fixture['pages']['root'], 1, $this->selection($fixture));
                    $assertions[] = $this->assertSame('partial', $this->treeStatus($tree, (int)$fixture['pages']['batchTests']), 'page with one translated element and no page target is partial');
                    break;
                case 'BT-SMOKE-003':
                    $selection = $this->selection($fixture, pages: [(int)$fixture['pages']['batchTests']]);
                    [$plan, $jobUid, $execution, $resultView] = $this->previewAndWrite($selection);
                    $targetPageUid = $this->targetPageUid((int)$fixture['pages']['batchTests']);
                    $targetContentUid = $this->targetContentUid((int)$fixture['content'][(string)$fixture['pages']['batchTests']]['e4']);
                    $assertions[] = $this->assertTrue($targetPageUid > 0, 'only-page creates selected page target');
                    $assertions[] = $this->assertSame(0, (int)($this->pageRecord($targetPageUid)['hidden'] ?? 1), 'written page translation is visible');
                    $assertions[] = $this->assertSame(0, $this->targetPageUid((int)$fixture['pages']['teamNotes']), 'only-page does not create child page target');
                    $assertions[] = $this->assertTrue($targetContentUid > 0, 'only-page creates page content targets');
                    $assertions[] = $this->assertSame(0, (int)($this->contentRecord($targetContentUid)['hidden'] ?? 1), 'written page content translation is visible');
                    break;
                case 'BT-SMOKE-004':
                    $selection = $this->selection($fixture, subtrees: [(int)$fixture['pages']['teamNotes']]);
                    [$plan, $jobUid, $execution, $resultView] = $this->previewAndWrite($selection);
                    foreach (['teamNotes', 'launchTasks', 'supportIdeas', 'editorialPlan'] as $pageKey) {
                        $assertions[] = $this->assertTrue($this->targetPageUid((int)$fixture['pages'][$pageKey]) > 0, 'recursive branch creates ' . $pageKey);
                    }
                    $assertions[] = $this->assertSame(0, $this->targetPageUid((int)$fixture['pages']['serviceDesk']), 'recursive branch does not create sibling branch');
                    break;
                case 'BT-SMOKE-005':
                    $excludedElement = (int)$fixture['content'][(string)$fixture['pages']['batchTests']]['e1'];
                    $selection = $this->selection($fixture, subtrees: [(int)$fixture['pages']['root']], excludedPages: [(int)$fixture['pages']['supportIdeas']], excludedElements: [$excludedElement]);
                    [$plan, $jobUid, $execution, $resultView] = $this->previewAndWrite($selection);
                    $assertions[] = $this->assertSame(0, $this->targetPageUid((int)$fixture['pages']['supportIdeas']), 'excluded page has no target');
                    $assertions[] = $this->assertSame(0, $this->targetContentUid($excludedElement), 'excluded element has no target');
                    $assertions[] = $this->assertTrue($this->targetPageUid((int)$fixture['pages']['launchTasks']) > 0, 'other branch page still written');
                    break;
                case 'BT-SMOKE-006':
                    $selection = $this->selection($fixture, pages: [(int)$fixture['pages']['standalonePage']], subtrees: [(int)$fixture['pages']['serviceDesk']], elements: [(int)$fixture['content'][(string)$fixture['pages']['batchTests']]['e4']]);
                    [$plan, $jobUid, $execution, $resultView] = $this->previewAndWrite($selection);
                    $assertions[] = $this->assertTrue($this->targetPageUid((int)$fixture['pages']['standalonePage']) > 0, 'mixed writes selected page');
                    $assertions[] = $this->assertTrue($this->targetPageUid((int)$fixture['pages']['serviceDesk']) > 0, 'mixed writes selected branch');
                    $assertions[] = $this->assertTrue($this->targetContentUid((int)$fixture['content'][(string)$fixture['pages']['batchTests']]['e4']) > 0, 'mixed writes selected element');
                    break;
                case 'BT-SMOKE-007':
                    $selection = $this->selection($fixture, subtrees: [(int)$fixture['pages']['root']], elements: [(int)$fixture['content'][(string)$fixture['pages']['batchTests']]['e4']]);
                    [$plan, $jobUid] = $this->previewOnly($selection);
                    $assertions[] = $this->assertTrue($this->noDuplicateFakeCalls($artifactRoot, $beforeCalls), 'overlapping selection has no duplicate Fake DeepL operation calls');
                    break;
                case 'BT-SMOKE-008':
                    $old = $this->pageTitle((int)$fixture['pages']['existingTranslationTarget']);
                    $selection = $this->selection($fixture, pages: [(int)$fixture['pages']['existingTranslationPage']]);
                    [$plan, $jobUid, $execution, $resultView] = $this->previewAndWrite($selection);
                    $assertions[] = $this->assertSame($old, $this->pageTitle((int)$fixture['pages']['existingTranslationTarget']), 'missing-only keeps existing target title');
                    break;
                case 'BT-SMOKE-009':
                    $selection = $this->selection($fixture, TranslationMode::RetranslateSelected, pages: [(int)$fixture['pages']['existingTranslationPage']]);
                    [$plan, $jobUid, $execution, $resultView] = $this->previewAndWrite($selection);
                    $assertions[] = $this->assertContains('[BT-SMOKE EN-US]', $this->pageTitle($this->targetPageUid((int)$fixture['pages']['existingTranslationPage'])), 'retranslate overwrites target title');
                    break;
                case 'BT-SMOKE-010':
                    $old = $this->pageTitle((int)$fixture['pages']['partialTranslationTarget']);
                    $selection = $this->selection($fixture, TranslationMode::UpdateEmptyFieldsOnly, pages: [(int)$fixture['pages']['partialTranslationPage']]);
                    [$plan, $jobUid, $execution, $resultView] = $this->previewAndWrite($selection);
                    $target = $this->pageRecord((int)$fixture['pages']['partialTranslationTarget']);
                    $assertions[] = $this->assertSame($old, (string)($target['title'] ?? ''), 'update-empty keeps non-empty target title');
                    $assertions[] = $this->assertContains('[BT-SMOKE EN-US]', (string)($target['description'] ?? ''), 'update-empty fills empty page description');
                    break;
                case 'BT-SMOKE-011':
                    $selection = $this->selection($fixture, TranslationMode::CreateMissingRecordsOnly, pages: [(int)$fixture['pages']['standalonePage']]);
                    [$plan, $jobUid, $execution, $resultView] = $this->previewAndWrite($selection);
                    $assertions[] = $this->assertSame($beforeCalls, $this->fakeCallCount($artifactRoot), 'create-records-only makes no Fake DeepL calls');
                    $assertions[] = $this->assertTrue($this->targetPageUid((int)$fixture['pages']['standalonePage']) > 0, 'create-records-only creates target page');
                    break;
                case 'BT-SMOKE-012':
                    $selection = $this->selection($fixture, subtrees: [(int)$fixture['pages']['permissionBlockedArea']]);
                    [$plan, $jobUid, $execution, $resultView] = $this->previewAndWrite($selection);
                    $assertions[] = $this->assertTrue(($plan?->counts()['blocked'] ?? 0) > 0, 'blocked branch is reported before write');
                    $assertions[] = $this->assertSame(0, $this->targetPageUid((int)$fixture['pages']['permissionBlockedArea']), 'blocked page has no target');
                    break;
                case 'BT-SMOKE-013':
                    $details = $this->pageTreeService->getPageDetails((int)$fixture['pages']['batchTests'], 0, 1, (int)$fixture['pages']['root']);
                    $longPreview = false;
                    foreach ($details['elements'] as $element) {
                        $longPreview = $longPreview || mb_strlen((string)$element['preview']) <= 150;
                    }
                    $assertions[] = $this->assertTrue($details['page'] !== null, 'page preview returns page details');
                    $assertions[] = $this->assertTrue($longPreview, 'page preview snippets are available and bounded');
                    $assertions[] = $this->assertSame($beforeCalls, $this->fakeCallCount($artifactRoot), 'page preview makes no Fake DeepL calls');
                    break;
                case 'BT-SMOKE-014':
                    $selection = $this->selection($fixture, pages: [(int)$fixture['pages']['batchTests']]);
                    $plan = $this->preflightService->buildPlan($selection);
                    $previewOne = $this->previewService->buildPreview($plan, 'DE', 'EN-US');
                    $callsAfterFirst = $this->fakeCallCount($artifactRoot);
                    $previewTwo = $this->previewService->buildPreview($plan, 'DE', 'EN-US', $previewOne['plan']);
                    $assertions[] = $this->assertSame($callsAfterFirst, $this->fakeCallCount($artifactRoot), 'cached preview avoids repeated Fake DeepL calls');
                    $plan = $previewTwo['plan'];
                    break;
                case 'BT-SMOKE-015':
                case 'BT-SMOKE-016':
                    $assertions[] = $this->assertSourceContains('data-confirm-discard-preview', 'discard warning is wired in template/javascript');
                    break;
                case 'BT-SMOKE-017':
                    $selection = $this->selection($fixture, pages: [(int)$fixture['pages']['standalonePage']]);
                    [$plan, $jobUid, $execution, $resultView] = $this->previewAndWrite($selection);
                    $assertions[] = $this->assertTrue(($resultView['counts']['writtenFields'] ?? 0) > 0, 'results view reports written fields');
                    $assertions[] = $this->assertTrue($this->resultHasBackendLink($resultView), 'results view contains backend target link');
                    break;
                case 'BT-SMOKE-018':
                    $sourceContentUid = (int)$fixture['content']['htmlBodytextElement'];
                    $selection = $this->selection($fixture, elements: [$sourceContentUid]);
                    [$plan, $jobUid, $execution, $resultView] = $this->previewAndWrite($selection);
                    $targetUid = $this->targetContentUid($sourceContentUid) ?: $this->targetUidFromResultView($resultView, 'tt_content', $sourceContentUid);
                    $target = $this->contentRecord($targetUid);
                    $assertions[] = $this->assertContains('<strong>', (string)($target['bodytext'] ?? ''), 'HTML bodytext keeps strong tag');
                    break;
                case 'BT-SMOKE-019':
                    $selection = $this->selection($fixture);
                    $plan = $this->preflightService->buildPlan($selection);
                    $assertions[] = $this->assertSame(0, $plan->counts()['items'], 'no-selection has no resolved items');
                    $assertions[] = $this->assertSame($beforeCalls, $this->fakeCallCount($artifactRoot), 'no-selection makes no Fake DeepL calls');
                    break;
                case 'BT-SMOKE-020':
                    $selection = $this->selectionNormalizer->fromRequest(['site_identifier' => SmokeFixtureService::SITE_IDENTIFIER, 'source_language_id' => 0, 'target_language_id' => 1]);
                    $basket = $this->basketSummaryService->summarize($selection, null);
                    $assertions[] = $this->assertTrue($basket['isEmpty'], 'clear-selection state has empty basket');
                    break;
                case 'BT-SMOKE-021':
                    $selection = $this->selection(
                        $fixture,
                        pages: [(int)$fixture['pages']['standalonePage']],
                        customInstructions: implode("\n", array_map(static fn(int $line): string => 'Instruction ' . $line, range(1, 10)))
                    );
                    [$plan, $jobUid] = $this->previewOnly($selection);
                    $calls = array_slice($this->fakeCalls($artifactRoot), $beforeCalls);
                    $lastCall = end($calls) ?: [];
                    $assertions[] = $this->assertSame(10, count(is_array($lastCall['customInstructions'] ?? null) ? $lastCall['customInstructions'] : []), 'ten custom instruction lines are forwarded');
                    $assertions[] = $this->assertContains('Instruction 10', implode("\n", is_array($lastCall['customInstructions'] ?? null) ? $lastCall['customInstructions'] : []), 'tenth custom instruction is kept');
                    break;
                case 'BT-SMOKE-022':
                    $selection = $this->selection(
                        $fixture,
                        pages: [(int)$fixture['pages']['standalonePage']],
                        customInstructions: implode("\n", array_map(static fn(int $line): string => 'Instruction ' . $line, range(1, 12)))
                    );
                    [$plan, $jobUid] = $this->previewOnly($selection);
                    $calls = array_slice($this->fakeCalls($artifactRoot), $beforeCalls);
                    $lastCall = end($calls) ?: [];
                    $instructions = is_array($lastCall['customInstructions'] ?? null) ? $lastCall['customInstructions'] : [];
                    $assertions[] = $this->assertSame(10, count($instructions), 'twelve custom instruction lines are capped at ten');
                    $assertions[] = $this->assertTrue(!in_array('Instruction 11', $instructions, true) && !in_array('Instruction 12', $instructions, true), 'instruction lines eleven and twelve are not forwarded');
                    break;
                case 'BT-SMOKE-023':
                    $selection = $this->selection($fixture, pages: [(int)$fixture['pages']['standalonePage']], targetLanguageId: SmokeFixtureService::THIRD_LANGUAGE_ID);
                    [$plan, $jobUid, $execution, $resultView] = $this->previewAndWrite($selection, targetLanguage: 'FR');
                    $calls = array_slice($this->fakeCalls($artifactRoot), $beforeCalls);
                    $lastCall = end($calls) ?: [];
                    $targetPageUid = $this->targetPageUid((int)$fixture['pages']['standalonePage'], SmokeFixtureService::THIRD_LANGUAGE_ID);
                    $targetContentUid = $this->targetContentUid((int)$fixture['content'][(string)$fixture['pages']['standalonePage']]['e4'], SmokeFixtureService::THIRD_LANGUAGE_ID);
                    $assertions[] = $this->assertTrue($targetPageUid > 0, 'default source writes third-language page target');
                    $assertions[] = $this->assertTrue($targetContentUid > 0, 'default source writes third-language content target');
                    $assertions[] = $this->assertSame('FR', (string)($lastCall['targetLanguage'] ?? ''), 'third-language preview requests FR');
                    $assertions[] = $this->assertSame(0, $this->targetPageUid((int)$fixture['pages']['standalonePage']), 'third-language write does not create English target');
                    break;
                case 'BT-SMOKE-024':
                    $selection = $this->selection($fixture, TranslationMode::CreateMissingRecordsOnly, pages: [(int)$fixture['pages']['standalonePage']], targetLanguageId: SmokeFixtureService::THIRD_LANGUAGE_ID);
                    [$plan, $jobUid, $execution, $resultView] = $this->previewAndWrite($selection, targetLanguage: 'FR');
                    $assertions[] = $this->assertSame($beforeCalls, $this->fakeCallCount($artifactRoot), 'third-language create-records-only makes no Fake DeepL calls');
                    $assertions[] = $this->assertTrue($this->targetPageUid((int)$fixture['pages']['standalonePage'], SmokeFixtureService::THIRD_LANGUAGE_ID) > 0, 'third-language create-records-only creates page target');
                    break;
                case 'BT-SMOKE-025':
                    $selection = $this->selection(
                        $fixture,
                        pages: [(int)$fixture['pages']['existingTranslationPage']],
                        sourceLanguageId: SmokeFixtureService::TARGET_LANGUAGE_ID,
                        targetLanguageId: SmokeFixtureService::THIRD_LANGUAGE_ID
                    );
                    [$plan, $jobUid, $execution, $resultView] = $this->previewAndWrite($selection, sourceLanguage: 'EN', targetLanguage: 'FR');
                    $assertions[] = $this->assertTrue($this->targetPageUid((int)$fixture['pages']['existingTranslationPage'], SmokeFixtureService::THIRD_LANGUAGE_ID) > 0, 'non-default source creates third-language target from base record');
                    $assertions[] = $this->assertTrue(($plan?->counts()['blocked'] ?? 1) === 0, 'non-default source with existing source localization is writable');
                    break;
                case 'BT-SMOKE-026':
                    $selection = $this->selection(
                        $fixture,
                        pages: [(int)$fixture['pages']['standalonePage']],
                        sourceLanguageId: SmokeFixtureService::TARGET_LANGUAGE_ID,
                        targetLanguageId: SmokeFixtureService::THIRD_LANGUAGE_ID
                    );
                    $plan = $this->preflightService->buildPlan($selection);
                    $assertions[] = $this->assertTrue(($plan->counts()['blocked'] ?? 0) > 0, 'missing non-default source localization is blocked');
                    $assertions[] = $this->assertContains('Selected source language record is missing.', json_encode($plan->toArray(), JSON_THROW_ON_ERROR), 'source-missing reason is recorded');
                    break;
                case 'BT-SMOKE-027':
                    $assertions[] = $this->assertSourceContains('preview_failed', 'preview failure status is available for failed suggestion jobs');
                    $assertions[] = $this->assertSourceContains('message.previewFailedWithJob', 'failed suggestion message is translation-ready');
                    break;
                case 'BT-SMOKE-028':
                    $selection = $this->selection($fixture, pages: [(int)$fixture['pages']['standalonePage']]);
                    [$plan, $jobUid] = $this->previewOnly($selection);
                    $GLOBALS['BE_USER'] = $this->fixtureService->limitedBackendUser();
                    $execution = $this->executionService->executePreviewJob($jobUid);
                    $assertions[] = $this->assertSame('error', (string)($execution['type'] ?? ''), 'another backend user cannot execute the job');
                    $assertions[] = $this->assertContains('only access batch translation jobs', (string)($execution['text'] ?? ''), 'job access denial is explicit');
                    break;
                case 'BT-SMOKE-029':
                    $cleanup = $this->jobLogger->cleanupFinishedJobs(time() + 3600, true);
                    $assertions[] = $this->assertTrue($cleanup['jobs'] >= 0 && $cleanup['items'] >= 0, 'cleanup dry-run returns counts');
                    break;
            }
        } catch (\Throwable $exception) {
            $messages[] = $exception->getMessage();
            $assertions[] = ['ok' => false, 'message' => $exception->getMessage()];
        }

        $failed = array_values(array_filter($assertions, static fn(array $assertion): bool => empty($assertion['ok'])));

        return [
            'caseId' => $caseId,
            'status' => $failed === [] ? 'PASS' : 'FAIL',
            'assertions' => $assertions,
            'messages' => $messages,
            'selection' => $selection?->toArray(),
            'preflight' => $plan?->toArray(),
            'jobUid' => $jobUid,
            'execution' => $execution,
            'resultView' => $resultView,
            'fakeDeepLCallsBefore' => $beforeCalls,
            'fakeDeepLCallsAfter' => $this->fakeCallCount($artifactRoot),
        ];
    }

    /**
     * @return array{0: PreflightPlan, 1: int}
     */
    private function previewOnly(BatchSelection $selection, string $sourceLanguage = 'DE', string $targetLanguage = 'EN-US'): array
    {
        $plan = $this->preflightService->buildPlan($selection);
        $preview = $this->previewService->buildPreview($plan, $sourceLanguage, $targetLanguage);
        $jobUid = $this->jobLogger->createJobFromPlan($preview['plan'], $preview['errors'] === [] ? 'previewed' : 'preview_failed');

        return [$preview['plan']->withJobUid($jobUid), $jobUid];
    }

    /**
     * @return array{0: PreflightPlan, 1: int, 2: array<string, mixed>, 3: array<string, mixed>}
     */
    private function previewAndWrite(BatchSelection $selection, string $sourceLanguage = 'DE', string $targetLanguage = 'EN-US'): array
    {
        [$plan, $jobUid] = $this->previewOnly($selection, $sourceLanguage, $targetLanguage);
        $execution = $this->executionService->executePreviewJob($jobUid);
        $resultView = $this->resultViewModelService->build($jobUid);

        return [$plan, $jobUid, $execution, $resultView];
    }

    /**
     * @param int[] $pages
     * @param int[] $subtrees
     * @param int[] $elements
     * @param int[] $excludedPages
     * @param int[] $excludedElements
     */
    private function selection(array $fixture, TranslationMode $mode = TranslationMode::TranslateMissingOnly, array $pages = [], array $subtrees = [], array $elements = [], array $excludedPages = [], array $excludedElements = [], int $sourceLanguageId = 0, int $targetLanguageId = SmokeFixtureService::TARGET_LANGUAGE_ID, string $customInstructions = ''): BatchSelection
    {
        return new BatchSelection(
            SmokeFixtureService::SITE_IDENTIFIER,
            $sourceLanguageId,
            $targetLanguageId,
            $mode,
            array_map(static fn(int $uid): PageSelection => new PageSelection($uid), $pages),
            array_map(static fn(int $uid): SubtreeSelection => new SubtreeSelection($uid), $subtrees),
            array_map(fn(int $uid): ElementSelection => new ElementSelection($uid, $this->contentPid($uid)), $elements),
            $excludedPages,
            $excludedElements,
            null,
            '',
            $customInstructions
        );
    }

    private function assertTrue(bool $condition, string $message): array
    {
        return ['ok' => $condition, 'message' => $message];
    }

    private function assertSame(mixed $expected, mixed $actual, string $message): array
    {
        return ['ok' => $expected === $actual, 'message' => $message, 'expected' => $expected, 'actual' => $actual];
    }

    private function assertContains(string $needle, string $haystack, string $message): array
    {
        return ['ok' => str_contains($haystack, $needle), 'message' => $message, 'needle' => $needle, 'actual' => $haystack];
    }

    private function assertSourceContains(string $needle, string $message): array
    {
        $basePath = dirname(__DIR__, 3);
        $found = false;
        foreach ([
            'Classes/Service/BatchWorkspaceService.php',
            'Resources/Private/Language/locallang.xlf',
            'Resources/Private/Templates/BatchTranslation/Index.html',
            'Resources/Public/Javascript/backend-scroll.js',
        ] as $file) {
            $path = $basePath . '/' . $file;
            $found = $found || (is_file($path) && str_contains((string)file_get_contents($path), $needle));
        }

        return $this->assertTrue($found, $message);
    }

    private function targetPageUid(int $sourceUid, int $targetLanguageId = SmokeFixtureService::TARGET_LANGUAGE_ID): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($targetLanguageId, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('l10n_parent', $queryBuilder->createNamedParameter($sourceUid, \PDO::PARAM_INT))
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? (int)$row['uid'] : 0;
    }

    private function targetContentUid(int $sourceUid, int $targetLanguageId = SmokeFixtureService::TARGET_LANGUAGE_ID): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('uid')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($targetLanguageId, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('l18n_parent', $queryBuilder->createNamedParameter($sourceUid, \PDO::PARAM_INT))
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? (int)$row['uid'] : 0;
    }

    private function contentPid(int $contentUid): int
    {
        $row = $this->contentRecord($contentUid);

        return (int)($row['pid'] ?? 0);
    }

    private function pageTitle(int $pageUid): string
    {
        $row = $this->pageRecord($pageUid);

        return (string)($row['title'] ?? '');
    }

    private function pageRecord(int $pageUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('*')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, \PDO::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : [];
    }

    private function contentRecord(int $contentUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('*')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($contentUid, \PDO::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : [];
    }

    /**
     * @param array<int, array<string, mixed>> $tree
     */
    private function treeStatus(array $tree, int $pageUid): string
    {
        foreach ($tree as $row) {
            if ((int)($row['uid'] ?? 0) === $pageUid) {
                return (string)($row['status'] ?? '');
            }
        }

        return '';
    }

    private function resultHasBackendLink(?array $resultView): bool
    {
        foreach (is_array($resultView['rows'] ?? null) ? $resultView['rows'] : [] as $row) {
            if ((string)($row['backendUrl'] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    private function targetUidFromResultView(?array $resultView, string $table, int $sourceUid): int
    {
        foreach (is_array($resultView['rows'] ?? null) ? $resultView['rows'] : [] as $row) {
            if ((string)($row['table'] ?? '') === $table && (int)($row['sourceUid'] ?? 0) === $sourceUid) {
                return (int)($row['targetUid'] ?? 0);
            }
        }

        return 0;
    }

    private function fakeCallCount(string $artifactRoot): int
    {
        return count($this->fakeCalls($artifactRoot));
    }

    private function noDuplicateFakeCalls(string $artifactRoot, int $offset = 0): bool
    {
        $seen = [];
        foreach (array_slice($this->fakeCalls($artifactRoot), $offset) as $call) {
            foreach (array_keys(is_array($call['texts'] ?? null) ? $call['texts'] : []) as $operationId) {
                if (isset($seen[$operationId])) {
                    return false;
                }
                $seen[$operationId] = true;
            }
        }

        return true;
    }

    private function fakeCalls(string $artifactRoot): array
    {
        $path = $artifactRoot . '/fake-deepl-calls.json';
        if (!is_file($path)) {
            return [];
        }
        try {
            $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    private function snapshot(array $fixture): array
    {
        $pageUids = array_values(array_filter(array_map('intval', $fixture['pages'] ?? [])));
        $contentUids = [];
        foreach (is_array($fixture['content'] ?? null) ? $fixture['content'] : [] as $value) {
            if (is_array($value)) {
                foreach ($value as $uid) {
                    $contentUids[] = (int)$uid;
                }
            } elseif (is_numeric($value)) {
                $contentUids[] = (int)$value;
            }
        }

        return [
            'pages' => $this->records('pages', $pageUids, 'l10n_parent'),
            'tt_content' => $this->records('tt_content', array_values(array_unique($contentUids)), 'l18n_parent'),
        ];
    }

    private function records(string $table, array $sourceUids, string $parentField): array
    {
        if ($sourceUids === []) {
            return [];
        }
        $connection = $this->connectionPool->getConnectionForTable($table);
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($sourceUids, \Doctrine\DBAL\ArrayParameterType::INTEGER)),
                    $queryBuilder->expr()->in($parentField, $queryBuilder->createNamedParameter($sourceUids, \Doctrine\DBAL\ArrayParameterType::INTEGER))
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }

    private function writeJson(string $path, array $data): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    private function writeSummary(string $path, array $summary): void
    {
        $lines = [
            '# Batch Translation Smoke Summary',
            '',
            '- Artifact root: `' . $summary['artifactRoot'] . '`',
            '- Started: ' . $summary['startedAt'],
            '- Finished: ' . ($summary['finishedAt'] ?? ''),
            '',
            '| Case | Status | Report |',
            '|---|---:|---|',
        ];
        foreach ($summary['cases'] as $case) {
            $lines[] = '| ' . $case['caseId'] . ' | ' . $case['status'] . ' | ' . $case['report'] . ' |';
        }
        $lines[] = '';
        $lines[] = 'Fake DeepL calls: `fake-deepl-calls.json`';
        $lines[] = 'DB snapshots: `db-before/` and `db-after/`';

        file_put_contents($path, implode("\n", $lines) . "\n");
    }
}
