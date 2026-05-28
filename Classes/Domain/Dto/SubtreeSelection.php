<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Domain\Dto;

final class SubtreeSelection
{
    public function __construct(
        public readonly int $rootPageUid,
        public readonly bool $includeRoot = true,
        public readonly bool $includeHidden = false,
        public readonly bool $includeElements = true
    ) {}

    /**
     * @return array{rootPageUid: int, includeRoot: bool, includeHidden: bool, includeElements: bool}
     */
    public function toArray(): array
    {
        return [
            'rootPageUid' => $this->rootPageUid,
            'includeRoot' => $this->includeRoot,
            'includeHidden' => $this->includeHidden,
            'includeElements' => $this->includeElements,
        ];
    }
}
