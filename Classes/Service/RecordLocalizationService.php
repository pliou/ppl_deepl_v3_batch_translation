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

    public function ensureLocalizedRecord(string $table, int $sourceUid, int $targetLanguageId, int $translationSourceUid = 0): int
    {
        $existingUid = $this->findLocalizedRecordUid($table, $sourceUid, $targetLanguageId);
        if ($existingUid > 0) {
            $this->setTranslationSource($table, $existingUid, $sourceUid, $translationSourceUid);
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
            $this->setTranslationSource($table, $existingUid, $sourceUid, $translationSourceUid);
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
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($targetLanguageId, \Doctrine\DBAL\ParameterType::INTEGER)),
                $queryBuilder->expr()->eq($translationPointer, $queryBuilder->createNamedParameter($sourceUid, \Doctrine\DBAL\ParameterType::INTEGER))
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

    public function translationSourceField(string $table): string
    {
        $field = (string)($GLOBALS['TCA'][$table]['ctrl']['translationSource'] ?? '');

        return $field !== '' && isset($GLOBALS['TCA'][$table]['columns'][$field]) ? $field : '';
    }

    private function setTranslationSource(string $table, int $targetUid, int $baseUid, int $translationSourceUid): void
    {
        if ($translationSourceUid <= 0 || $translationSourceUid === $baseUid || $targetUid <= 0) {
            return;
        }

        $field = $this->translationSourceField($table);
        if ($field === '') {
            return;
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([
            $table => [
                $targetUid => [
                    $field => $translationSourceUid,
                ],
            ],
        ], []);
        $dataHandler->process_datamap();
    }
}
