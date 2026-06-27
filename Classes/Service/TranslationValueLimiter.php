<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service;

use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\FieldOperation;

final class TranslationValueLimiter
{
    public function limit(FieldOperation $operation): string
    {
        $value = $operation->translatedValue;
        $max = $GLOBALS['TCA'][$operation->table]['columns'][$operation->field]['config']['max'] ?? 0;
        if (!is_numeric($max) || (int)$max <= 0 || mb_strlen($value) <= (int)$max) {
            return $value;
        }

        if ($operation->tagHandling === 'html' || str_contains($value, '<')) {
            return $value;
        }

        return mb_substr($value, 0, (int)$max);
    }
}
