<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service;

use TYPO3\CMS\Core\Core\Environment;

final class TranslationResourceOptionService
{
    public const DEFAULT_SOURCE_LANGUAGE = 'EN';
    public const DEFAULT_TARGET_LANGUAGE = 'DE';

    private const STORAGE_DIRECTORIES = [
        'ppl_deepl_v3_batch_translation',
        'ppl_deepl_v3_requests',
        'ppl_deepl',
    ];

    public function normalizeSourceLanguage(string $language): string
    {
        $language = strtoupper(str_replace('_', '-', trim($language)));

        return match (true) {
            $language === 'DE-DE' => 'DE',
            str_starts_with($language, 'EN-') => 'EN',
            str_starts_with($language, 'PT-') => 'PT',
            str_starts_with($language, 'ES-') => 'ES',
            $language === 'ZH-HANS' || $language === 'ZH-HANT' => 'ZH',
            str_contains($language, '-') => explode('-', $language, 2)[0],
            default => $language,
        };
    }

    public function normalizeTargetLanguage(string $language): string
    {
        $language = strtoupper(str_replace('_', '-', trim($language)));

        return match (true) {
            $language === 'EN' => 'EN-GB',
            $language === 'PT' => 'PT-PT',
            $language === 'DE-DE' => 'DE',
            str_starts_with($language, 'EN-') => $language,
            str_starts_with($language, 'PT-') => $language,
            $language === 'ZH-HANS' || $language === 'ZH-HANT' => 'ZH',
            str_contains($language, '-') => explode('-', $language, 2)[0],
            default => $language,
        };
    }

    public function normalizeGlossaryLanguage(string $language): string
    {
        return $this->normalizeSourceLanguage($language);
    }

    public function normalizeStyleRuleLanguage(string $language): string
    {
        $language = strtoupper(str_replace('_', '-', trim($language)));

        return match (true) {
            str_starts_with($language, 'EN') => 'EN',
            $language === 'DE' || $language === 'DE-DE' => 'DE',
            str_starts_with($language, 'ES') => 'ES',
            str_starts_with($language, 'FR') => 'FR',
            str_starts_with($language, 'IT') => 'IT',
            str_starts_with($language, 'JA') => 'JA',
            str_starts_with($language, 'KO') => 'KO',
            str_starts_with($language, 'ZH') => 'ZH',
            default => '',
        };
    }

    public function buildGlossaryCombinationKey(string $sourceLanguage, string $targetLanguage): string
    {
        return $this->normalizeGlossaryLanguage($sourceLanguage)
            . ':'
            . $this->normalizeGlossaryLanguage($targetLanguage);
    }

    /**
     * @return array<string, string>
     */
    public function getGlossaryOptionsForLanguagePair(string $sourceLanguage, string $targetLanguage): array
    {
        $combinationKey = $this->buildGlossaryCombinationKey($sourceLanguage, $targetLanguage);

        return $this->getGlossaryOptionsByCombination()[$combinationKey] ?? [];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function getGlossaryOptionsByCombination(): array
    {
        $optionsByCombination = [];

        foreach ($this->getEnabledGlossaries() as $glossary) {
            foreach (($glossary['dictionaries'] ?? []) as $dictionary) {
                $combinationKey = (string)($dictionary['combinationKey'] ?? '');
                $id = (string)($glossary['id'] ?? '');
                if ($combinationKey === '' || $id === '') {
                    continue;
                }

                $optionsByCombination[$combinationKey][$id] = (string)($glossary['name'] ?? $id)
                    . ' ('
                    . (string)($dictionary['sourceLang'] ?? '')
                    . ' -> '
                    . (string)($dictionary['targetLang'] ?? '')
                    . ')';
            }
        }

        return $optionsByCombination;
    }

    public function isGlossaryAvailableForLanguagePair(string $glossaryId, string $sourceLanguage, string $targetLanguage): bool
    {
        return $glossaryId !== ''
            && array_key_exists($glossaryId, $this->getGlossaryOptionsForLanguagePair($sourceLanguage, $targetLanguage));
    }

    /**
     * @return array<string, string>
     */
    public function getStyleRuleOptionsForLanguage(string $targetLanguage): array
    {
        $targetLanguage = $this->normalizeStyleRuleLanguage($targetLanguage);
        if ($targetLanguage === '') {
            return [];
        }

        return $this->getStyleRuleOptionsByLanguage()[$targetLanguage] ?? [];
    }

    /**
     * @return array<string, array{label: string, language: string}>
     */
    public function getStyleRuleDisplayOptions(): array
    {
        $options = [];

        foreach ($this->getEnabledStyleRules() as $styleRule) {
            $id = (string)($styleRule['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $options[$id] = [
                'label' => (string)($styleRule['label'] ?? $styleRule['name'] ?? $id),
                'language' => (string)($styleRule['language'] ?? ''),
            ];
        }

        return $options;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function getStyleRuleOptionsByLanguage(): array
    {
        $optionsByLanguage = [];

        foreach ($this->getEnabledStyleRules() as $styleRule) {
            $id = (string)($styleRule['id'] ?? '');
            $language = (string)($styleRule['language'] ?? '');
            if ($id === '' || $language === '') {
                continue;
            }

            $optionsByLanguage[$language][$id] = (string)($styleRule['label'] ?? $styleRule['name'] ?? $id);
        }

        return $optionsByLanguage;
    }

    public function isStyleRuleAvailableForLanguage(string $styleRuleId, string $targetLanguage): bool
    {
        return $styleRuleId !== ''
            && array_key_exists($styleRuleId, $this->getStyleRuleOptionsForLanguage($targetLanguage));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getEnabledGlossaries(): array
    {
        $data = $this->readStorageJson('glossaries.json');
        $glossaries = $data['glossaries'] ?? [];
        if (!is_array($glossaries)) {
            return [];
        }

        $normalized = [];
        foreach ($glossaries as $glossary) {
            if (!is_array($glossary) || !(bool)($glossary['enabled'] ?? true)) {
                continue;
            }

            $glossary['dictionaries'] = $this->normalizeDictionaries((array)($glossary['dictionaries'] ?? []));
            $normalized[] = $glossary;
        }

        return $normalized;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getEnabledStyleRules(): array
    {
        $data = $this->readStorageJson('style-rules.json');
        $styleRules = $data['styleRules'] ?? [];
        if (!is_array($styleRules)) {
            return [];
        }

        $normalized = [];
        foreach ($styleRules as $styleRule) {
            if (!is_array($styleRule) || !(bool)($styleRule['enabled'] ?? true)) {
                continue;
            }

            $id = (string)($styleRule['id'] ?? $styleRule['style_id'] ?? '');
            if ($id === '') {
                continue;
            }

            $language = $this->normalizeStyleRuleLanguage((string)($styleRule['language'] ?? ''));
            $name = (string)($styleRule['name'] ?? $id);
            $styleRule['id'] = $id;
            $styleRule['language'] = $language;
            $styleRule['label'] = trim((string)($styleRule['label'] ?? ($name . ($language !== '' ? ' (' . $language . ')' : ''))));
            $normalized[] = $styleRule;
        }

        return $normalized;
    }

    /**
     * @param array<int, mixed> $dictionaries
     * @return array<int, array<string, mixed>>
     */
    private function normalizeDictionaries(array $dictionaries): array
    {
        $normalized = [];

        foreach ($dictionaries as $dictionary) {
            if (!is_array($dictionary) && !is_object($dictionary)) {
                continue;
            }

            $sourceLanguage = $this->readValue($dictionary, 'source_lang', 'sourceLang');
            $targetLanguage = $this->readValue($dictionary, 'target_lang', 'targetLang');
            if ($sourceLanguage === '' || $targetLanguage === '') {
                continue;
            }

            $sourceLanguage = $this->normalizeGlossaryLanguage($sourceLanguage);
            $targetLanguage = $this->normalizeGlossaryLanguage($targetLanguage);
            $normalized[] = [
                'sourceLang' => $sourceLanguage,
                'targetLang' => $targetLanguage,
                'entryCount' => (int)$this->readValue($dictionary, 'entry_count', 'entryCount'),
                'combinationKey' => $this->buildGlossaryCombinationKey($sourceLanguage, $targetLanguage),
            ];
        }

        return $normalized;
    }

    private function readValue(array|object $source, string ...$keys): string
    {
        foreach ($keys as $key) {
            if (is_array($source) && array_key_exists($key, $source)) {
                return (string)$source[$key];
            }

            if (is_object($source) && isset($source->{$key})) {
                return (string)$source->{$key};
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function readStorageJson(string $fileName): array
    {
        $varPath = $this->getVarPath();
        foreach (self::STORAGE_DIRECTORIES as $directory) {
            $storageFile = $varPath . '/' . $directory . '/' . $fileName;
            if (!is_file($storageFile)) {
                continue;
            }

            $data = json_decode((string)file_get_contents($storageFile), true);
            if (is_array($data)) {
                return $data;
            }
        }

        return [];
    }

    private function getVarPath(): string
    {
        try {
            $varPath = Environment::getVarPath();
            if ($varPath !== '') {
                return $varPath;
            }
        } catch (\Throwable) {
        }

        return rtrim((string)getcwd(), '/') . '/var';
    }
}
