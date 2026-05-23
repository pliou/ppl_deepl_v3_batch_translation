<?php

declare(strict_types=1);

use Ppl\PplDeeplV3BatchTranslation\Controller\BatchTranslationController;

return [
    'ppl_deepl' => [
        'position' => ['after' => 'system'],
        'iconIdentifier' => 'module-ppl-deepl',
        'labels' => [
            'title' => 'LLL:EXT:ppl_deepl_v3_batch_translation/Resources/Private/Language/locallang.xlf:module.root.title',
            'shortDescription' => 'LLL:EXT:ppl_deepl_v3_batch_translation/Resources/Private/Language/locallang.xlf:module.root.description',
        ],
    ],
    'ppl_deepl_v3_batch_translation' => [
        'parent' => 'ppl_deepl',
        'position' => ['after' => 'ppl_deepl_v3_file_translation'],
        'access' => 'user',
        'path' => '/module/ppl-deepl/v3-batch-translation',
        'iconIdentifier' => 'module-ppl-deepl-v3-batch-translation',
        'labels' => [
            'title' => 'LLL:EXT:ppl_deepl_v3_batch_translation/Resources/Private/Language/locallang.xlf:module.batch.title',
            'shortDescription' => 'LLL:EXT:ppl_deepl_v3_batch_translation/Resources/Private/Language/locallang.xlf:module.batch.description',
        ],
        'routes' => [
            '_default' => [
                'target' => BatchTranslationController::class . '::handleRequest',
            ],
        ],
    ],
];
