<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

final class BatchTranslationOptionService
{
    public function __construct(
        private readonly SiteFinder $siteFinder
    ) {}

    public function getPrimarySite(): ?Site
    {
        $sites = $this->siteFinder->getAllSites();
        if ($sites === []) {
            return null;
        }

        return reset($sites) ?: null;
    }

    public function getSourceLanguage(Site $site): ?SiteLanguage
    {
        foreach ($site->getLanguages() as $language) {
            if ((int)$language->getLanguageId() === 0) {
                return $language;
            }
        }

        return null;
    }

    public function getTargetLanguage(Site $site, int $targetLanguageId = 0): ?SiteLanguage
    {
        $fallback = null;
        foreach ($site->getLanguages() as $language) {
            if ((int)$language->getLanguageId() <= 0) {
                continue;
            }

            $fallback ??= $language;
            if ($targetLanguageId > 0 && (int)$language->getLanguageId() === $targetLanguageId) {
                return $language;
            }
        }

        return $fallback;
    }

    public function resolveTargetLanguageId(array $requestData): int
    {
        return max(0, (int)($requestData['target_language_id'] ?? 0));
    }

    public function getTargetLanguageOptions(Site $site, int $selectedTargetLanguageId): array
    {
        $options = [];
        foreach ($site->getLanguages() as $language) {
            $languageId = (int)$language->getLanguageId();
            if ($languageId <= 0) {
                continue;
            }

            $options[] = [
                'code' => $this->toDeepLTargetLanguage($language),
                'id' => $languageId,
                'selected' => $selectedTargetLanguageId > 0 && $languageId === $selectedTargetLanguageId,
                'title' => method_exists($language, 'getTitle') ? (string)$language->getTitle() : 'Language ' . $languageId,
            ];
        }

        if ($options !== [] && !array_filter($options, static fn(array $option): bool => (bool)$option['selected'])) {
            $options[0]['selected'] = true;
        }

        return $options;
    }

    public function toDeepLSourceLanguage(SiteLanguage $language): string
    {
        $code = $this->siteLanguageToCode($language);

        return match (true) {
            str_starts_with($code, 'EN-') => 'EN',
            str_starts_with($code, 'PT-') => 'PT',
            default => $code,
        };
    }

    public function toDeepLTargetLanguage(SiteLanguage $language): string
    {
        $code = $this->siteLanguageToCode($language);

        return match (true) {
            $code === 'EN' => 'EN-GB',
            $code === 'PT' => 'PT-PT',
            default => $code,
        };
    }

    public function getGlossaryOptionsForLanguagePair(string $sourceLanguage, string $targetLanguage): array
    {
        $combinationKey = $this->buildGlossaryCombinationKey($sourceLanguage, $targetLanguage);
        $options = [];

        foreach ($this->readJsonList('ppl_deepl/glossaries.json', 'glossaries') as $glossary) {
            if (!(bool)($glossary['enabled'] ?? true)) {
                continue;
            }

            $id = (string)($glossary['id'] ?? '');
            if ($id === '') {
                continue;
            }

            foreach ((array)($glossary['dictionaries'] ?? []) as $dictionary) {
                if (!is_array($dictionary) || (string)($dictionary['combinationKey'] ?? '') !== $combinationKey) {
                    continue;
                }

                $options[$id] = (string)($glossary['name'] ?? $id)
                    . ' (' . (string)($dictionary['sourceLang'] ?? '') . ' -> ' . (string)($dictionary['targetLang'] ?? '') . ')';
            }
        }

        return $options;
    }

    public function resolveGlossaryId(string $sourceLanguage, string $targetLanguage, string $preferredGlossaryId): string
    {
        $preferredGlossaryId = trim($preferredGlossaryId);
        $options = $this->getGlossaryOptionsForLanguagePair($sourceLanguage, $targetLanguage);

        if ($preferredGlossaryId !== '' && isset($options[$preferredGlossaryId])) {
            return $preferredGlossaryId;
        }

        return '';
    }

    public function getStyleRuleOptionsForTargetLanguage(string $targetLanguage): array
    {
        $targetLanguage = $this->normalizeStyleRuleLanguage($targetLanguage);
        if ($targetLanguage === '') {
            return [];
        }

        $options = [];
        foreach ($this->readJsonList('ppl_deepl_v3_translate/style-rules.json', 'styleRules') as $styleRule) {
            if (!(bool)($styleRule['enabled'] ?? true)) {
                continue;
            }

            $id = (string)($styleRule['id'] ?? '');
            if ($id === '' || (string)($styleRule['language'] ?? '') !== $targetLanguage) {
                continue;
            }

            $options[$id] = (string)($styleRule['label'] ?? $styleRule['name'] ?? $id);
        }

        return $options;
    }

    public function resolveStyleRuleId(string $targetLanguage, string $preferredStyleRuleId): string
    {
        $preferredStyleRuleId = trim($preferredStyleRuleId);
        $options = $this->getStyleRuleOptionsForTargetLanguage($targetLanguage);

        if ($preferredStyleRuleId !== '' && isset($options[$preferredStyleRuleId])) {
            return $preferredStyleRuleId;
        }

        return '';
    }

    public function normalizeCustomInstructions(string $customInstructions): array
    {
        $lines = preg_split('/\R+/', $customInstructions) ?: [];
        $instructions = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $instructions[] = substr($line, 0, 300);
            }
        }

        return array_slice(array_values(array_unique($instructions)), 0, 10);
    }

    private function buildGlossaryCombinationKey(string $sourceLanguage, string $targetLanguage): string
    {
        return $this->normalizeGlossaryLanguage($sourceLanguage) . ':' . $this->normalizeGlossaryLanguage($targetLanguage);
    }

    private function normalizeGlossaryLanguage(string $language): string
    {
        $language = strtoupper($language);

        return match (true) {
            str_starts_with($language, 'EN-') => 'EN',
            str_starts_with($language, 'PT-') => 'PT',
            $language === 'ZH-HANS' || $language === 'ZH-HANT' => 'ZH',
            default => $language,
        };
    }

    private function normalizeStyleRuleLanguage(string $language): string
    {
        $language = strtoupper($language);

        return match (true) {
            str_starts_with($language, 'EN') => 'EN',
            $language === 'DE' => 'DE',
            $language === 'ES' => 'ES',
            $language === 'FR' => 'FR',
            $language === 'IT' => 'IT',
            $language === 'JA' => 'JA',
            $language === 'KO' => 'KO',
            str_starts_with($language, 'ZH') => 'ZH',
            default => '',
        };
    }

    private function siteLanguageToCode(SiteLanguage $language): string
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

        $normalized = strtoupper(str_replace('_', '-', preg_replace('/\..*$/', '', trim($locale)) ?? ''));
        if ($normalized === '') {
            return '';
        }

        $parts = explode('-', $normalized);
        $languageCode = strtoupper((string)($parts[0] ?? ''));
        $regionCode = strtoupper((string)($parts[1] ?? ''));

        if ($languageCode === 'EN') {
            return $regionCode === 'US' ? 'EN-US' : 'EN-GB';
        }

        if ($languageCode === 'PT') {
            return $regionCode === 'BR' ? 'PT-BR' : 'PT-PT';
        }

        return $languageCode;
    }

    private function readJsonList(string $relativeFile, string $listKey): array
    {
        $filePath = Environment::getVarPath() . '/' . $relativeFile;
        if (!is_file($filePath)) {
            return [];
        }

        $data = json_decode((string)file_get_contents($filePath), true);
        if (!is_array($data) || !is_array($data[$listKey] ?? null)) {
            return [];
        }

        return array_values(array_filter($data[$listKey], 'is_array'));
    }
}
