<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'PPL DeepL V3 Batch Translation',
    'description' => 'TYPO3 backend module for controlled DeepL V3 batch translation.',
    'category' => 'module',
    'author' => 'Pawel Pliousnin',
    'author_email' => 'pliousnin@ppl-ds.com',
    'state' => 'alpha',
    'version' => '12.4.0',
    'clearCacheOnLoad' => 0,
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-12.4.99',
            'backend' => '12.4.0-12.4.99',
            'ppl_deepl_v3_requests' => '12.4.0-12.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
