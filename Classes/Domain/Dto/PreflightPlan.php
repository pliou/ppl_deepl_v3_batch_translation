<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Domain\Dto;

final class PreflightPlan
{
    /**
     * @param PreflightItem[] $items
     * @param array<int, array{type: string, text: string}> $messages
     */
    public function __construct(
        public readonly BatchSelection $selection,
        public readonly array $items = [],
        public readonly array $messages = [],
        public readonly int $jobUid = 0
    ) {}

    public function withJobUid(int $jobUid): self
    {
        return new self($this->selection, $this->items, $this->messages, $jobUid);
    }

    /**
     * @return FieldOperation[]
     */
    public function writableFieldOperations(): array
    {
        $operations = [];
        foreach ($this->items as $item) {
            foreach ($item->writableFieldOperations() as $operation) {
                $operations[] = $operation;
            }
        }

        return $operations;
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [
            'items' => count($this->items),
            'blocked' => 0,
            'skipped' => 0,
            'createRecords' => 0,
            'updateRecords' => 0,
            'fields' => 0,
            'overwrites' => 0,
            'fills' => 0,
            'translations' => 0,
            'characters' => 0,
            'pages' => 0,
            'elements' => 0,
        ];

        foreach ($this->items as $item) {
            if ($item->itemType === 'page') {
                $counts['pages']++;
            } elseif ($item->itemType === 'element') {
                $counts['elements']++;
            }
            if ($item->isBlocked()) {
                $counts['blocked']++;
                continue;
            }

            $writableOperations = $item->writableFieldOperations();
            if ($item->recordAction === 'create') {
                $counts['createRecords']++;
            } elseif ($item->recordAction === 'update') {
                if ($writableOperations !== []) {
                    $counts['updateRecords']++;
                } else {
                    $counts['skipped']++;
                }
            } elseif ($item->recordAction === 'skip') {
                $counts['skipped']++;
            }
            foreach ($writableOperations as $operation) {
                $counts['fields']++;
                if ($operation->writeAction === 'overwrite') {
                    $counts['overwrites']++;
                } elseif ($operation->writeAction === 'fill_empty') {
                    $counts['fills']++;
                } elseif ($operation->writeAction === 'translate') {
                    $counts['translations']++;
                }
                if ($operation->needsTranslation()) {
                    $counts['characters'] += mb_strlen($operation->sourceValue);
                }
            }
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'jobUid' => $this->jobUid,
            'selection' => $this->selection->toArray(),
            'items' => array_map(static fn(PreflightItem $item): array => $item->toArray(), $this->items),
            'messages' => $this->messages,
            'counts' => $this->counts(),
        ];
    }
}
