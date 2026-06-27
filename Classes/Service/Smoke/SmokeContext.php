<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service\Smoke;

use TYPO3\CMS\Core\Core\Environment;

final class SmokeContext
{
    private const CONTEXT_FILE = 'ppl_deepl_v3_batch_translation/smoke-context.json';

    public function isActive(): bool
    {
        return $this->canUseSmokeProvider() && ($this->envEnabled() || $this->readContext() !== []);
    }

    public function artifactRoot(): string
    {
        $envRoot = trim((string)getenv('PPL_BATCH_TRANSLATION_SMOKE_ARTIFACT_ROOT'));
        if ($envRoot !== '') {
            return $envRoot;
        }

        $context = $this->readContext();
        $root = trim((string)($context['artifactRoot'] ?? ''));
        if ($root !== '') {
            return $root;
        }

        return Environment::getVarPath() . '/smoke/batch-translation/manual';
    }

    public function activate(string $artifactRoot): void
    {
        if (!$this->canUseSmokeProvider()) {
            throw new \RuntimeException('Fake DeepL smoke context is only allowed in TYPO3 Development or Testing context.');
        }

        $path = $this->contextFilePath();
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($path, json_encode([
            'enabled' => true,
            'artifactRoot' => $artifactRoot,
            'createdAt' => date(DATE_ATOM),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    public function deactivate(): void
    {
        $path = $this->contextFilePath();
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function fakeDeeplCallLogPath(): string
    {
        return rtrim($this->artifactRoot(), '/') . '/fake-deepl-calls.json';
    }

    private function envEnabled(): bool
    {
        return in_array(strtolower(trim((string)getenv('PPL_BATCH_TRANSLATION_SMOKE'))), ['1', 'true', 'yes'], true);
    }

    private function canUseSmokeProvider(): bool
    {
        $context = Environment::getContext();

        return $context->isDevelopment() || $context->isTesting();
    }

    /**
     * @return array<string, mixed>
     */
    private function readContext(): array
    {
        $path = $this->contextFilePath();
        if (!is_file($path)) {
            return [];
        }

        try {
            $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($data) && !empty($data['enabled']) ? $data : [];
    }

    private function contextFilePath(): string
    {
        return Environment::getVarPath() . '/' . self::CONTEXT_FILE;
    }
}
