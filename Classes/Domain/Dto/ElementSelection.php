<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Domain\Dto;

final class ElementSelection
{
    public function __construct(
        public readonly int $contentUid,
        public readonly int $sourcePageUid = 0
    ) {}

    /**
     * @return array{contentUid: int, sourcePageUid: int}
     */
    public function toArray(): array
    {
        return [
            'contentUid' => $this->contentUid,
            'sourcePageUid' => $this->sourcePageUid,
        ];
    }
}
