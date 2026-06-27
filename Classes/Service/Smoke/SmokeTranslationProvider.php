<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service\Smoke;

use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\TranslationBatchRequest;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\TranslationBatchResult;
use Ppl\PplDeeplV3BatchTranslation\Service\TranslationProviderInterface;

final class SmokeTranslationProvider implements TranslationProviderInterface
{
    public function __construct(
        private readonly SmokeContext $context
    ) {}

    public function translateBatch(TranslationBatchRequest $request): TranslationBatchResult
    {
        $translations = [];
        foreach ($request->texts as $operationId => $text) {
            $translations[$operationId] = $this->translateText((string)$text, $request->targetLanguage, $request->tagHandling);
        }

        $this->appendCall($request, $translations);

        return new TranslationBatchResult($translations, []);
    }

    private function translateText(string $text, string $targetLanguage, string $tagHandling): string
    {
        $prefix = '[BT-SMOKE ' . $targetLanguage . '] ';
        if (!str_contains($text, '<')) {
            return $prefix . $text;
        }

        if ($tagHandling === 'html' || str_contains($text, '<')) {
            return preg_replace_callback('/>([^<]+)</', static function (array $matches) use ($prefix): string {
                $value = trim((string)$matches[1]);
                if ($value === '') {
                    return $matches[0];
                }

                return '>' . $prefix . $matches[1] . '<';
            }, $text) ?? ($prefix . $text);
        }

        return $prefix . $text;
    }

    /**
     * @param array<string, string> $translations
     */
    private function appendCall(TranslationBatchRequest $request, array $translations): void
    {
        $path = $this->context->fakeDeeplCallLogPath();
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $calls = [];
        if (is_file($path)) {
            try {
                $decoded = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                $calls = is_array($decoded) ? $decoded : [];
            } catch (\Throwable) {
                $calls = [];
            }
        }

        $calls[] = [
            'createdAt' => date(DATE_ATOM),
            'sourceLanguage' => $request->sourceLanguage,
            'targetLanguage' => $request->targetLanguage,
            'glossaryId' => $request->glossaryId,
            'styleRuleId' => $request->styleRuleId,
            'tagHandling' => $request->tagHandling,
            'customInstructions' => $request->customInstructions,
            'texts' => $request->texts,
            'translations' => $translations,
        ];

        file_put_contents($path, json_encode($calls, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
