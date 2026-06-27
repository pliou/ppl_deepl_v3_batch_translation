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
        return $this->ensureLocalizedRecordWithOrigin($table, $sourceUid, $targetLanguageId, $translationSourceUid)['uid'];
    }

    /**
     * Like ensureLocalizedRecord(), but also reports whether THIS call created the localized record
     * or merely found one that already existed.
     *
     * The distinction is safety-critical for the batch: a translation that appeared between preview
     * and execution (created by another editor or job) must never be treated as "created by us" and
     * deleted by a later-step compensation. Only `created === true` records are ours to roll back.
     *
     * @return array{uid: int, created: bool}
     */
    public function ensureLocalizedRecordWithOrigin(string $table, int $sourceUid, int $targetLanguageId, int $translationSourceUid = 0): array
    {
        $existingUid = $this->findLocalizedRecordUid($table, $sourceUid, $targetLanguageId);
        if ($existingUid > 0) {
            $this->setTranslationSource($table, $existingUid, $sourceUid, $translationSourceUid);
            return ['uid' => $existingUid, 'created' => false];
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

        $createdUid = $this->findLocalizedRecordUid($table, $sourceUid, $targetLanguageId);
        if ($createdUid > 0) {
            $this->setTranslationSource($table, $createdUid, $sourceUid, $translationSourceUid);
            return ['uid' => $createdUid, 'created' => true];
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
