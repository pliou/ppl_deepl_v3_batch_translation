<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'PPL DeepL V3 Batch Translation',
    'description' => 'TYPO3 backend workspace for controlled page and content element batch translation using ppl_deepl_v3_requests.',
    'category' => 'module',
    'author' => 'Pawel Pliousnin',
    'author_email' => 'pliousnin@ppl-ds.com',
    'state' => 'stable',
    'clearCacheOnLoad' => true,
    'version' => '12.4.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-12.4.99',
            'backend' => '12.4.0-12.4.99',
            'extbase' => '12.4.0-12.4.99',
            'fluid' => '12.4.0-12.4.99',
            'ppl_deepl_v3_requests' => '12.4.0-12.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
