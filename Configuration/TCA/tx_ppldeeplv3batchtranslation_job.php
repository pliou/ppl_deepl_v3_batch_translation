<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:ppl_deepl_v3_batch_translation/Resources/Private/Language/locallang.xlf:tca.job',
        'label' => 'site_identifier',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'hideTable' => true,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'site_identifier, source_language_id, target_language_id, translation_mode, status, total_items, processed_items, blocked_items, skipped_items, failed_items, translated_items',
        ],
    ],
    'columns' => [
        'site_identifier' => ['config' => ['type' => 'input', 'readOnly' => true]],
        'source_language_id' => ['config' => ['type' => 'number', 'readOnly' => true]],
        'target_language_id' => ['config' => ['type' => 'number', 'readOnly' => true]],
        'translation_mode' => ['config' => ['type' => 'input', 'readOnly' => true]],
        'status' => ['config' => ['type' => 'input', 'readOnly' => true]],
        'selected_scope_json' => ['config' => ['type' => 'text', 'readOnly' => true]],
        'options_json' => ['config' => ['type' => 'text', 'readOnly' => true]],
        'total_items' => ['config' => ['type' => 'number', 'readOnly' => true]],
        'processed_items' => ['config' => ['type' => 'number', 'readOnly' => true]],
        'blocked_items' => ['config' => ['type' => 'number', 'readOnly' => true]],
        'skipped_items' => ['config' => ['type' => 'number', 'readOnly' => true]],
        'failed_items' => ['config' => ['type' => 'number', 'readOnly' => true]],
        'translated_items' => ['config' => ['type' => 'number', 'readOnly' => true]],
        'created_by' => ['config' => ['type' => 'number', 'readOnly' => true]],
        'started_at' => ['config' => ['type' => 'number', 'readOnly' => true]],
        'finished_at' => ['config' => ['type' => 'number', 'readOnly' => true]],
    ],
];
