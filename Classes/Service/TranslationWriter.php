<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service;

use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\FieldOperation;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\DataHandling\SlugHelper;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class TranslationWriter
{
    public function __construct(
        private readonly TranslationValueLimiter $valueLimiter
    ) {}

    /**
     * @param FieldOperation[] $operations
     * @return array{writtenFields: int, errors: string[]}
     */
    public function write(array $operations): array
    {
        $dataMap = [];
        $writtenFields = 0;

        foreach ($operations as $operation) {
            if ($operation->targetUid <= 0 || trim($operation->translatedValue) === '') {
                continue;
            }

            $dataMap[$operation->table][$operation->targetUid][$operation->field] = $this->valueLimiter->limit($operation);
            $writtenFields++;
        }

        if ($dataMap === []) {
            return [
                'writtenFields' => 0,
                'errors' => [],
            ];
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($dataMap, []);
        $dataHandler->process_datamap();

        return [
            'writtenFields' => $writtenFields,
            'errors' => is_array($dataHandler->errorLog ?? null) ? $dataHandler->errorLog : [],
        ];
    }

    /**
     * @return string[]
     */
    public function unhideRecord(string $table, int $targetUid): array
    {
        if ($targetUid <= 0 || !isset($GLOBALS['TCA'][$table]['columns']['hidden'])) {
            return [];
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([
            $table => [
                $targetUid => [
                    'hidden' => 0,
                ],
            ],
        ], []);
        $dataHandler->process_datamap();

        return is_array($dataHandler->errorLog ?? null) ? $dataHandler->errorLog : [];
    }

    /**
     * @return string[]
     */
    public function regeneratePageSlug(int $targetPageUid): array
    {
        if ($targetPageUid <= 0 || !isset($GLOBALS['TCA']['pages']['columns']['slug']['config'])) {
            return [];
        }

        $page = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('pages')
            ->select(['*'], 'pages', ['uid' => $targetPageUid, 'deleted' => 0])
            ->fetchAssociative();
        if (!is_array($page) || (int)($page['sys_language_uid'] ?? 0) <= 0 || trim((string)($page['title'] ?? '')) === '') {
            return [];
        }

        $configuration = (array)$GLOBALS['TCA']['pages']['columns']['slug']['config'];
        $slugHelper = GeneralUtility::makeInstance(SlugHelper::class, 'pages', 'slug', $configuration, 0);
        $slug = $slugHelper->generate($page, (int)($page['pid'] ?? 0));
        if ($slug === '' || $slug === (string)($page['slug'] ?? '')) {
            return [];
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([
            'pages' => [
                $targetPageUid => [
                    'slug' => $slug,
                ],
            ],
        ], []);
        $dataHandler->process_datamap();

        return is_array($dataHandler->errorLog ?? null) ? $dataHandler->errorLog : [];
    }

    /**
     * Compensating delete for a target record this batch run created but could not finish
     * writing/making consistent. Lets a later re-run start clean instead of leaving an
     * orphaned, half-written localization behind.
     *
     * @return string[]
     */
    public function deleteRecord(string $table, int $targetUid): array
    {
        if ($table === '' || $targetUid <= 0) {
            return [];
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [
            $table => [
                $targetUid => [
                    'delete' => 1,
                ],
            ],
        ]);
        $dataHandler->process_cmdmap();

        return is_array($dataHandler->errorLog ?? null) ? $dataHandler->errorLog : [];
    }

}
