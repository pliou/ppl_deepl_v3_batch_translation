<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service\Smoke;

use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\TranslationBatchRequest;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\TranslationBatchResult;
use Ppl\PplDeeplV3BatchTranslation\Service\DeeplV3TranslationProvider;
use Ppl\PplDeeplV3BatchTranslation\Service\TranslationProviderInterface;

final class SmokeAwareTranslationProvider implements TranslationProviderInterface
{
    public function __construct(
        private readonly SmokeContext $context,
        private readonly SmokeTranslationProvider $smokeProvider,
        private readonly DeeplV3TranslationProvider $realProvider
    ) {}

    public function translateBatch(TranslationBatchRequest $request): TranslationBatchResult
    {
        if ($this->context->isActive()) {
            return $this->smokeProvider->translateBatch($request);
        }

        return $this->realProvider->translateBatch($request);
    }
}
