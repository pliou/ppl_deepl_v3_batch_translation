<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Domain\Dto;

final class FieldDefinition
{
    public function __construct(
        public readonly string $table,
        public readonly string $field,
        public readonly string $label,
        public readonly string $mode = 'plain',
        public readonly int $maxLength = 0
    ) {}
}
