<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;

final class BatchRecordMappingService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly RecordLocalizationService $localizationService
    ) {}

    /**
     * @return array{base: array<string, mixed>|null, source: array<string, mixed>|null, sourceMissing: bool, sourceUid: int, baseUid: int}
     */
    public function resolveSourceRecord(string $table, int $baseUid, int $sourceLanguageId): array
    {
        $base = $this->fetchRecord($table, $baseUid);
        if ($base === null) {
            return [
                'base' => null,
                'source' => null,
                'sourceMissing' => true,
                'sourceUid' => 0,
                'baseUid' => $baseUid,
            ];
        }

        if ($sourceLanguageId <= 0) {
            return [
                'base' => $base,
                'source' => $base,
                'sourceMissing' => false,
                'sourceUid' => $baseUid,
                'baseUid' => $baseUid,
            ];
        }

        $sourceUid = $this->localizationService->findLocalizedRecordUid($table, $baseUid, $sourceLanguageId);
        $source = $sourceUid > 0 ? $this->fetchRecord($table, $sourceUid) : null;

        return [
            'base' => $base,
            'source' => $source,
            'sourceMissing' => $source === null,
            'sourceUid' => $source !== null ? (int)$source['uid'] : 0,
            'baseUid' => $baseUid,
        ];
    }

    public function targetUid(string $table, int $baseUid, int $targetLanguageId): int
    {
        return $this->localizationService->findLocalizedRecordUid($table, $baseUid, $targetLanguageId);
    }

    public function targetRecord(string $table, int $baseUid, int $targetLanguageId): ?array
    {
        $targetUid = $this->targetUid($table, $baseUid, $targetLanguageId);

        return $targetUid > 0 ? $this->fetchRecord($table, $targetUid) : null;
    }

    public function fetchRecord(string $table, int $uid): ?array
    {
        if ($uid <= 0) {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, \Doctrine\DBAL\ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    public function translationSourceField(string $table): string
    {
        $field = (string)($GLOBALS['TCA'][$table]['ctrl']['translationSource'] ?? '');

        return $field !== '' && isset($GLOBALS['TCA'][$table]['columns'][$field]) ? $field : '';
    }
}
