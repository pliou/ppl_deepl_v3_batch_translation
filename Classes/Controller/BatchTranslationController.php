<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Controller;

use Ppl\PplDeeplV3BatchTranslation\Service\BatchPageTreeService;
use Ppl\PplDeeplV3BatchTranslation\Service\BatchTranslationOptionService;
use Ppl\PplDeeplV3BatchTranslation\Service\BatchTranslationExecutionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

#[AsController]
final class BatchTranslationController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly PageRenderer $pageRenderer,
        private readonly BatchPageTreeService $batchPageTreeService,
        private readonly BatchTranslationOptionService $batchTranslationOptionService,
        private readonly BatchTranslationExecutionService $batchTranslationExecutionService
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $requestData = is_array($body) ? $body : $request->getQueryParams();
        $selectedTargetLanguageId = $this->batchTranslationOptionService->resolveTargetLanguageId($requestData);
        $messages = is_array($body) ? $this->batchTranslationExecutionService->execute($body) : [];

        $this->pageRenderer->addCssFile('EXT:ppl_deepl_v3_batch_translation/Resources/Public/Css/backend.css');
        $this->pageRenderer->addJsFile('EXT:ppl_deepl_v3_batch_translation/Resources/Public/Javascript/backend-scroll.js', 'module', true, false, '', true);

        $site = $this->batchTranslationOptionService->getPrimarySite();
        $sourceLanguage = $site !== null ? $this->batchTranslationOptionService->getSourceLanguage($site) : null;
        $targetLanguage = $site !== null ? $this->batchTranslationOptionService->getTargetLanguage($site, $selectedTargetLanguageId) : null;
        $sourceLanguageCode = $sourceLanguage !== null ? $this->batchTranslationOptionService->toDeepLSourceLanguage($sourceLanguage) : '';
        $targetLanguageCode = $targetLanguage !== null ? $this->batchTranslationOptionService->toDeepLTargetLanguage($targetLanguage) : '';
        $selectedGlossaryId = $this->batchTranslationOptionService->resolveGlossaryId(
            $sourceLanguageCode,
            $targetLanguageCode,
            (string)($requestData['glossary_id'] ?? '')
        );
        $selectedStyleRuleId = $this->batchTranslationOptionService->resolveStyleRuleId(
            $targetLanguageCode,
            (string)($requestData['style_rule_id'] ?? '')
        );
        $customInstructions = trim((string)($requestData['custom_instructions'] ?? ''));

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setModuleClass('ppl-deepl-v3-batch-translation-module');
        $moduleTemplate->setTitle(LocalizationUtility::translate('batch.title', 'PplDeeplV3BatchTranslation') ?? 'PPL DeepL V3 Batch Translation');
        $moduleTemplate->assignMultiple($this->batchPageTreeService->buildTreeData($selectedTargetLanguageId) + [
            'customInstructions' => $customInstructions,
            'glossaryOptions' => $sourceLanguageCode !== '' && $targetLanguageCode !== ''
                ? $this->batchTranslationOptionService->getGlossaryOptionsForLanguagePair($sourceLanguageCode, $targetLanguageCode)
                : [],
            'messages' => $messages,
            'selectedGlossaryId' => $selectedGlossaryId,
            'selectedStyleRuleId' => $selectedStyleRuleId,
            'sourceLanguageCode' => $sourceLanguageCode,
            'styleRuleOptions' => $targetLanguageCode !== ''
                ? $this->batchTranslationOptionService->getStyleRuleOptionsForTargetLanguage($targetLanguageCode)
                : [],
            'targetLanguageCode' => $targetLanguageCode,
            'targetLanguageOptions' => $site !== null
                ? $this->batchTranslationOptionService->getTargetLanguageOptions($site, $selectedTargetLanguageId)
                : [],
        ]);

        return $moduleTemplate->renderResponse('BatchTranslation/Index');
    }
}
