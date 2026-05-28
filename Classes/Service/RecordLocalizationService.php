<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service;

use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class RecordLocalizationService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool
    ) {}

    public function ensureLocalizedRecord(string $table, int $sourceUid, int $targetLanguageId): int
    {
        $existingUid = $this->findLocalizedRecordUid($table, $sourceUid, $targetLanguageId);
        if ($existingUid > 0) {
            return $existingUid;
        }

        $commandMap = [
            $table => [
                $sourceUid => [
                    'localize' => $targetLanguageId,
                ],
            ],
        ];
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $commandMap);
        $dataHandler->process_cmdmap();

        $existingUid = $this->findLocalizedRecordUid($table, $sourceUid, $targetLanguageId);
        if ($existingUid > 0) {
            return $existingUid;
        }

        $errors = is_array($dataHandler->errorLog ?? null) ? implode('; ', $dataHandler->errorLog) : '';
        throw new \RuntimeException(sprintf('Could not localize %s:%d.%s', $table, $sourceUid, $errors !== '' ? ' ' . $errors : ''));
    }

    public function findLocalizedRecordUid(string $table, int $sourceUid, int $targetLanguageId): int
    {
        $translationPointer = $this->translationPointerField($table);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($targetLanguageId, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq($translationPointer, $queryBuilder->createNamedParameter($sourceUid, \PDO::PARAM_INT))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? (int)$row['uid'] : 0;
    }

    public function translationPointerField(string $table): string
    {
        return (string)($GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'] ?? match ($table) {
            'tt_content' => 'l18n_parent',
            default => 'l10n_parent',
        });
    }
}
