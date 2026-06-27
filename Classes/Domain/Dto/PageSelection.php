<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Domain\Dto;

final class PageSelection
{
    public function __construct(
        public readonly int $pageUid,
        public readonly bool $includePageRecord = true,
        public readonly bool $includeElements = true
    ) {}

    /**
     * @return array{pageUid: int, includePageRecord: bool, includeElements: bool}
     */
    public function toArray(): array
    {
        return [
            'pageUid' => $this->pageUid,
            'includePageRecord' => $this->includePageRecord,
            'includeElements' => $this->includeElements,
        ];
    }
}
