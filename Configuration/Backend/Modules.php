<?php

declare(strict_types=1);

use Ppl\PplDeeplV3BatchTranslation\Controller\BatchTranslationController;

return [
    'ppl_deepl_v3_batch_translation' => [
        'parent' => 'ppl_deepl_v3',
        'position' => ['after' => 'ppl_deepl_v3_file_translation'],
        'access' => 'user',
        'path' => '/module/ppl-deepl-v3/batch-translation',
        'iconIdentifier' => 'module-ppl-deepl-v3-batch-translation',
        'labels' => [
            'title' => 'LLL:EXT:ppl_deepl_v3_batch_translation/Resources/Private/Language/locallang.xlf:module.batchTranslation.title',
            'shortDescription' => 'LLL:EXT:ppl_deepl_v3_batch_translation/Resources/Private/Language/locallang.xlf:module.batchTranslation.description',
        ],
        'routes' => [
            '_default' => [
                'target' => BatchTranslationController::class . '::handleRequest',
            ],
        ],
    ],
];
