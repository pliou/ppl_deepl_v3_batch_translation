<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Domain\Dto;

final class PreflightItem
{
    /**
     * @param FieldOperation[] $fieldOperations
     * @param string[] $errors
     */
    public function __construct(
        public readonly string $itemId,
        public readonly string $itemType,
        public readonly string $table,
        public readonly int $sourceUid,
        public readonly int $targetUid,
        public readonly int $sourcePageUid,
        public readonly string $label,
        public readonly string $status,
        public readonly string $recordAction,
        public readonly PermissionResult $permission,
        public readonly array $fieldOperations = [],
        public readonly array $errors = []
    ) {}

    public function isBlocked(): bool
    {
        return !$this->permission->allowed || $this->errors !== [];
    }

    /**
     * @return FieldOperation[]
     */
    public function writableFieldOperations(): array
    {
        if ($this->isBlocked()) {
            return [];
        }

        return array_values(array_filter(
            $this->fieldOperations,
            static fn(FieldOperation $operation): bool => in_array($operation->writeAction, ['translate', 'fill_empty', 'overwrite'], true)
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'itemId' => $this->itemId,
            'itemType' => $this->itemType,
            'table' => $this->table,
            'sourceUid' => $this->sourceUid,
            'targetUid' => $this->targetUid,
            'sourcePageUid' => $this->sourcePageUid,
            'label' => $this->label,
            'status' => $this->status,
            'recordAction' => $this->recordAction,
            'permission' => $this->permission->toArray(),
            'fieldOperations' => array_map(static fn(FieldOperation $operation): array => $operation->toArray(), $this->fieldOperations),
            'errors' => $this->errors,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $permission = is_array($data['permission'] ?? null)
            ? PermissionResult::fromArray($data['permission'])
            : PermissionResult::blocked('Stored permission result is missing.');
        $operations = [];
        foreach (is_array($data['fieldOperations'] ?? null) ? $data['fieldOperations'] : [] as $operationData) {
            if (is_array($operationData)) {
                $operations[] = FieldOperation::fromArray($operationData);
            }
        }

        return new self(
            (string)($data['itemId'] ?? ''),
            (string)($data['itemType'] ?? ''),
            (string)($data['table'] ?? ''),
            (int)($data['sourceUid'] ?? 0),
            (int)($data['targetUid'] ?? 0),
            (int)($data['sourcePageUid'] ?? 0),
            (string)($data['label'] ?? ''),
            (string)($data['status'] ?? ''),
            (string)($data['recordAction'] ?? 'skip'),
            $permission,
            $operations,
            array_values(array_map('strval', is_array($data['errors'] ?? null) ? $data['errors'] : []))
        );
    }
}
