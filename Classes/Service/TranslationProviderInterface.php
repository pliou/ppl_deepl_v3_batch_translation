<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service;

use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\TranslationBatchRequest;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\TranslationBatchResult;

interface TranslationProviderInterface
{
    public function translateBatch(TranslationBatchRequest $request): TranslationBatchResult;
}
