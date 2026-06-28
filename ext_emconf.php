<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'PPL DeepL V3 Batch Translation',
    'description' => 'TYPO3 backend workspace for controlled page and content element batch translation using ppl_deepl_v3_requests.',
    'category' => 'module',
    'author' => 'Pawel Pliousnin',
    'author_email' => 'pliousnin@ppl-ds.com',
    'state' => 'stable',
    'clearCacheOnLoad' => true,
    'version' => '14.3.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.0.0-14.99.99',
            'backend' => '14.0.0-14.99.99',
            'extbase' => '14.0.0-14.99.99',
            'fluid' => '14.0.0-14.99.99',
            'ppl_deepl_v3_requests' => '14.0.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
