<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service;

use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\BatchSelection;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\ElementSelection;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\PageSelection;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\SubtreeSelection;
use Ppl\PplDeeplV3BatchTranslation\Domain\Enum\TranslationMode;

final class SelectionNormalizer
{
    public function fromRequest(array $body): BatchSelection
    {
        $pageUids = $this->normalizeIntList($body['selected_pages'] ?? []);
        $subtreeUids = $this->normalizeIntList($body['selected_subtree_pages'] ?? []);
        $elements = $this->normalizeElementSelections($body['selected_elements'] ?? []);
        $excludedPageUids = $this->normalizeIntList($body['excluded_pages'] ?? []);
        $excludedElementUids = $this->normalizeElementUidList($body['excluded_elements'] ?? []);

        return new BatchSelection(
            trim((string)($body['site_identifier'] ?? '')),
            (int)($body['source_language_id'] ?? 0),
            (int)($body['target_language_id'] ?? 0),
            TranslationMode::fromRequestValue((string)($body['translation_mode'] ?? '')),
            array_map(static fn(int $uid): PageSelection => new PageSelection($uid), $pageUids),
            array_map(static fn(int $uid): SubtreeSelection => new SubtreeSelection($uid), $subtreeUids),
            $elements,
            $excludedPageUids,
            $excludedElementUids,
            trim((string)($body['glossary_id'] ?? '')) ?: null,
            trim((string)($body['style_rule_id'] ?? '')),
            trim((string)($body['custom_instructions'] ?? ''))
        );
    }

    /**
     * @return int[]
     */
    private function normalizeIntList(mixed $value): array
    {
        if (!is_array($value)) {
            $value = $value === null || $value === '' ? [] : [$value];
        }

        $lookup = [];
        foreach ($value as $item) {
            $uid = (int)$item;
            if ($uid > 0) {
                $lookup[$uid] = true;
            }
        }

        return array_keys($lookup);
    }

    /**
     * @return int[]
     */
    private function normalizeElementUidList(mixed $value): array
    {
        if (!is_array($value)) {
            $value = $value === null || $value === '' ? [] : [$value];
        }

        $lookup = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $uid = (int)($item['contentUid'] ?? $item['uid'] ?? 0);
            } else {
                $parts = explode(':', (string)$item, 2);
                $uid = (int)($parts[0] ?? 0);
            }
            if ($uid > 0) {
                $lookup[$uid] = true;
            }
        }

        return array_keys($lookup);
    }

    /**
     * @return ElementSelection[]
     */
    private function normalizeElementSelections(mixed $value): array
    {
        if (!is_array($value)) {
            $value = $value === null || $value === '' ? [] : [$value];
        }

        $lookup = [];
        $elements = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $contentUid = (int)($item['contentUid'] ?? $item['uid'] ?? 0);
                $sourcePageUid = (int)($item['sourcePageUid'] ?? $item['pid'] ?? 0);
            } else {
                $parts = explode(':', (string)$item, 2);
                $contentUid = (int)($parts[0] ?? 0);
                $sourcePageUid = (int)($parts[1] ?? 0);
            }

            if ($contentUid <= 0 || isset($lookup[$contentUid])) {
                continue;
            }

            $lookup[$contentUid] = true;
            $elements[] = new ElementSelection($contentUid, $sourcePageUid);
        }

        return $elements;
    }
}
