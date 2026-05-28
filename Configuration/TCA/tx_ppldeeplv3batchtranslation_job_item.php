<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:ppl_deepl_v3_batch_translation/Resources/Private/Language/locallang.xlf:tca.jobItem',
        'label' => 'source_uid',
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
            'showitem' => 'job_uid, item_type, source_table, source_uid, target_uid, source_page_uid, status, error_message',
        ],
    ],
    'columns' => [
        'job_uid' => ['config' => ['type' => 'number', 'readOnly' => true]],
        'item_type' => ['config' => ['type' => 'input', 'readOnly' => true]],
        'source_table' => ['config' => ['type' => 'input', 'readOnly' => true]],
        'source_uid' => ['config' => ['type' => 'number', 'readOnly' => true]],
        'target_uid' => ['config' => ['type' => 'number', 'readOnly' => true]],
        'source_page_uid' => ['config' => ['type' => 'number', 'readOnly' => true]],
        'status' => ['config' => ['type' => 'input', 'readOnly' => true]],
        'error_message' => ['config' => ['type' => 'text', 'readOnly' => true]],
        'source_hash' => ['config' => ['type' => 'input', 'readOnly' => true]],
        'options_json' => ['config' => ['type' => 'text', 'readOnly' => true]],
        'processed_at' => ['config' => ['type' => 'number', 'readOnly' => true]],
    ],
];
