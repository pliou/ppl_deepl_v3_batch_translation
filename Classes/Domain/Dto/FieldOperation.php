<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Domain\Dto;

final class FieldOperation
{
    public function __construct(
        public readonly string $operationId,
        public readonly string $table,
        public readonly int $sourceUid,
        public readonly int $targetUid,
        public readonly int $sourcePageUid,
        public readonly string $field,
        public readonly string $label,
        public readonly string $sourceValue,
        public readonly string $targetValue,
        public readonly string $writeAction,
        public readonly string $tagHandling = '',
        public readonly string $translatedValue = ''
    ) {}

    public function needsTranslation(): bool
    {
        return in_array($this->writeAction, ['translate', 'fill_empty', 'overwrite'], true)
            && trim($this->sourceValue) !== '';
    }

    public function withTranslatedValue(string $translatedValue): self
    {
        return new self(
            $this->operationId,
            $this->table,
            $this->sourceUid,
            $this->targetUid,
            $this->sourcePageUid,
            $this->field,
            $this->label,
            $this->sourceValue,
            $this->targetValue,
            $this->writeAction,
            $this->tagHandling,
            $translatedValue
        );
    }

    public function withTargetUid(int $targetUid): self
    {
        return new self(
            $this->operationId,
            $this->table,
            $this->sourceUid,
            $targetUid,
            $this->sourcePageUid,
            $this->field,
            $this->label,
            $this->sourceValue,
            $this->targetValue,
            $this->writeAction,
            $this->tagHandling,
            $this->translatedValue
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'operationId' => $this->operationId,
            'table' => $this->table,
            'sourceUid' => $this->sourceUid,
            'targetUid' => $this->targetUid,
            'sourcePageUid' => $this->sourcePageUid,
            'field' => $this->field,
            'label' => $this->label,
            'sourceValue' => $this->sourceValue,
            'targetValue' => $this->targetValue,
            'writeAction' => $this->writeAction,
            'tagHandling' => $this->tagHandling,
            'translatedValue' => $this->translatedValue,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string)($data['operationId'] ?? ''),
            (string)($data['table'] ?? ''),
            (int)($data['sourceUid'] ?? 0),
            (int)($data['targetUid'] ?? 0),
            (int)($data['sourcePageUid'] ?? 0),
            (string)($data['field'] ?? ''),
            (string)($data['label'] ?? ''),
            (string)($data['sourceValue'] ?? ''),
            (string)($data['targetValue'] ?? ''),
            (string)($data['writeAction'] ?? ''),
            (string)($data['tagHandling'] ?? ''),
            (string)($data['translatedValue'] ?? '')
        );
    }
}
