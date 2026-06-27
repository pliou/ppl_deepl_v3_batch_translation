<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

final class BatchJobAccessGuard
{
    public function __construct(
        private readonly BatchJobLogger $jobLogger
    ) {}

    public function canAccessJob(int $jobUid): bool
    {
        $stored = $this->jobLogger->loadJob($jobUid);

        return $stored !== null && $this->canAccessStoredJob($stored['job']);
    }

    /**
     * @param array<string, mixed> $job
     */
    public function canAccessStoredJob(array $job): bool
    {
        $backendUser = $this->backendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return false;
        }

        if ($this->isAdmin($backendUser)) {
            return true;
        }

        $createdBy = (int)($job['created_by'] ?? 0);

        return $createdBy > 0 && $createdBy === $this->backendUserId($backendUser);
    }

    public function accessDeniedMessage(): string
    {
        return $this->translate('message.jobAccessDenied');
    }

    public function currentBackendUserId(): int
    {
        $backendUser = $this->backendUser();

        return $backendUser instanceof BackendUserAuthentication ? $this->backendUserId($backendUser) : 0;
    }

    private function backendUser(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $backendUser instanceof BackendUserAuthentication ? $backendUser : null;
    }

    private function backendUserId(BackendUserAuthentication $backendUser): int
    {
        return (int)($backendUser->user['uid'] ?? 0);
    }

    private function isAdmin(BackendUserAuthentication $backendUser): bool
    {
        return !empty($backendUser->user['admin']);
    }

    private function translate(string $key): string
    {
        return LocalizationUtility::translate($key, 'ppl_deepl_v3_batch_translation') ?? $key;
    }
}
