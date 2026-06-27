<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service;

use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\FieldDefinition;

final class TranslationFieldDefinitionService
{
    private const P0_FIELDS = [
        'pages' => [
            'title',
            'nav_title',
            'subtitle',
            'description',
            'abstract',
            'keywords',
            'seo_title',
            'og_title',
            'og_description',
            'twitter_title',
            'twitter_description',
        ],
        'tt_content' => [
            'header',
            'subheader',
            'bodytext',
            'imagecaption',
            'imagealttext',
            'imagetitletext',
            'table_caption',
        ],
    ];

    /**
     * @return FieldDefinition[]
     */
    public function getDefinitions(string $table): array
    {
        $fields = self::P0_FIELDS[$table] ?? [];
        $definitions = [];
        $columns = $GLOBALS['TCA'][$table]['columns'] ?? null;

        foreach ($fields as $field) {
            if (is_array($columns) && !isset($columns[$field])) {
                continue;
            }

            $definitions[] = new FieldDefinition(
                $table,
                $field,
                $this->labelForField($table, $field),
                $field === 'bodytext' ? 'html' : 'plain',
                $this->maxLengthForField($table, $field)
            );
        }

        return $definitions;
    }

    public function supportsTable(string $table): bool
    {
        return isset(self::P0_FIELDS[$table]);
    }

    private function labelForField(string $table, string $field): string
    {
        $label = (string)($GLOBALS['TCA'][$table]['columns'][$field]['label'] ?? '');
        if ($label !== '' && !str_starts_with($label, 'LLL:')) {
            return $label;
        }

        return ucwords(str_replace('_', ' ', $field));
    }

    private function maxLengthForField(string $table, string $field): int
    {
        $max = $GLOBALS['TCA'][$table]['columns'][$field]['config']['max'] ?? 0;

        return is_numeric($max) ? (int)$max : 0;
    }
}
