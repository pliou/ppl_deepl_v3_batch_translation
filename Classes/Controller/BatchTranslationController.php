<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Controller;

use Ppl\PplDeeplV3BatchTranslation\Service\BatchWorkspaceService;
use Ppl\PplDeeplV3BatchTranslation\Service\BatchResultViewModelService;
use Ppl\PplDeeplV3BatchTranslation\Service\BatchJobAccessGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

#[AsController]
final class BatchTranslationController
{
    private const FORM_NAME = 'ppl_deepl_v3_batch_translation';
    private const FORM_ACTION = 'workspace';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly PageRenderer $pageRenderer,
        private readonly FormProtectionFactory $formProtectionFactory,
        private readonly BatchWorkspaceService $workspaceService,
        private readonly BatchResultViewModelService $resultViewModelService,
        private readonly BatchJobAccessGuard $jobAccessGuard,
        private readonly ResponseFactoryInterface $responseFactory
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $formProtection = $this->formProtectionFactory->createFromRequest($request);
        $formToken = $formProtection->generateToken(self::FORM_NAME, self::FORM_ACTION);
        $messages = [];
        $action = (string)($body['module_action'] ?? '');
        $normalizedAction = $this->normalizeAction($action);
        $isJsonPreviewRequest = $this->isJsonPreviewRequest($request, $body, $normalizedAction);

        if (in_array($normalizedAction, ['clear_selection', 'restart_scan', 'generate_preview', 'write_translations', 'retranslate_selected', 'discard_preview', 'export_result_log'], true)
            && !$formProtection->validateToken((string)($body['form_token'] ?? ''), self::FORM_NAME, self::FORM_ACTION)
        ) {
            $body['module_action'] = '';
            $messages[] = [
                'type' => 'error',
                'text' => $this->translate('message.invalidFormToken'),
            ];
            $normalizedAction = '';
        }

        if ($normalizedAction === 'export_result_log') {
            $jobUid = max(0, (int)($body['result_job_uid'] ?? ($body['confirmed_job_uid'] ?? 0)));
            if (!$this->jobAccessGuard->canAccessJob($jobUid)) {
                $response = $this->responseFactory->createResponse(403)
                    ->withHeader('Content-Type', 'text/plain; charset=utf-8');
                $response->getBody()->write($this->jobAccessGuard->accessDeniedMessage());

                return $response;
            }
            $csv = $this->resultViewModelService->buildCsv($jobUid);
            $response = $this->responseFactory->createResponse(200)
                ->withHeader('Content-Type', 'text/csv; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="ppl-batch-translation-result-' . $jobUid . '.csv"');
            $response->getBody()->write($csv);

            return $response;
        }

        $viewData = $this->workspaceService->handle($body, $messages);

        if ($isJsonPreviewRequest) {
            $response = $this->responseFactory->createResponse(200)
                ->withHeader('Content-Type', 'application/json; charset=utf-8');
            $response->getBody()->write(json_encode([
                'ok' => !$this->hasErrorMessage($viewData['messages'] ?? []),
                'messages' => $viewData['messages'] ?? [],
                'confirmedJobUid' => (int)($viewData['confirmedJobUid'] ?? 0),
                'confirmedJobStatus' => (string)($viewData['confirmedJobStatus'] ?? ''),
                'workspaceStage' => (string)($viewData['workspaceStage'] ?? ''),
                'preflight' => $viewData['preflight'] ?? null,
                'actionState' => $viewData['actionState'] ?? [],
            ], JSON_THROW_ON_ERROR));

            return $response;
        }

        $this->pageRenderer->addCssFile('EXT:ppl_deepl_v3_batch_translation/Resources/Public/Css/backend.css');
        $this->pageRenderer->addJsFile('EXT:ppl_deepl_v3_batch_translation/Resources/Public/Javascript/backend-scroll.js', 'module', true, false, '', true);

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setModuleClass('ppl-batch-translation-module');
        $moduleTemplate->setTitle($this->translate('backend.title'));
        $moduleTemplate->assignMultiple(array_merge($viewData, [
            'route' => (string)$this->uriBuilder->buildUriFromRoute('ppl_deepl_v3_batch_translation'),
            'formToken' => $formToken,
        ]));

        return $moduleTemplate->renderResponse('BatchTranslation/Index');
    }

    private function translate(string $key): string
    {
        return LocalizationUtility::translate($key, 'PplDeeplV3BatchTranslation') ?? $key;
    }

    private function normalizeAction(string $action): string
    {
        if (str_starts_with($action, 'generate_preview:')) {
            return 'generate_preview';
        }
        return match ($action) {
            'preview' => 'generate_preview',
            'execute' => 'write_translations',
            default => $action,
        };
    }

    private function isJsonPreviewRequest(ServerRequestInterface $request, array $body, string $normalizedAction): bool
    {
        if (!in_array($normalizedAction, ['generate_preview', 'retranslate_selected'], true)) {
            return false;
        }

        return (string)($body['ajax_preview'] ?? '') === '1'
            || strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';
    }

    /**
     * @param mixed[] $messages
     */
    private function hasErrorMessage(array $messages): bool
    {
        foreach ($messages as $message) {
            if (is_array($message) && (string)($message['type'] ?? '') === 'error') {
                return true;
            }
        }

        return false;
    }
}
