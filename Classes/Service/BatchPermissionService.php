<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service;

use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\PermissionResult;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

final class BatchPermissionService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public function checkRecordAccess(string $table, array $row, int $sourcePageUid, int $targetLanguageId): PermissionResult
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return PermissionResult::blocked($this->translate('permission.noBackendUser'));
        }

        if (!empty($backendUser->user['admin'])) {
            return PermissionResult::allowed();
        }

        $reasons = [];
        if (method_exists($backendUser, 'check') && !$backendUser->check('tables_modify', $table)) {
            $reasons[] = sprintf($this->translate('permission.missingTableModify'), $table);
        }
        if (method_exists($backendUser, 'checkLanguageAccess') && !$backendUser->checkLanguageAccess($targetLanguageId)) {
            $reasons[] = sprintf($this->translate('permission.noTargetLanguageAccess'), $targetLanguageId);
        }

        $page = $table === 'pages' ? $row : $this->fetchRecord('pages', $sourcePageUid);
        if ($page === null) {
            $reasons[] = sprintf($this->translate('permission.sourcePageNotFound'), $sourcePageUid);
        } else {
            $permission = $table === 'tt_content' ? Permission::CONTENT_EDIT : Permission::PAGE_EDIT;
            if (method_exists($backendUser, 'doesUserHaveAccess') && !$backendUser->doesUserHaveAccess($page, $permission)) {
                $reasons[] = $table === 'tt_content'
                    ? $this->translate('permission.missingParentContentEdit')
                    : $this->translate('permission.missingPageEdit');
            }
        }

        return $reasons === [] ? PermissionResult::allowed() : PermissionResult::blocked(...$reasons);
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

    private function translate(string $key): string
    {
        return LocalizationUtility::translate($key, 'ppl_deepl_v3_batch_translation') ?? $key;
    }
}
