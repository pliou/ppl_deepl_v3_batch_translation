<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ppl\PplDeeplV3BatchTranslation\Service\BatchJobAccessGuard;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class BatchJobAccessGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
    }

    public function testAllowsJobCreator(): void
    {
        $GLOBALS['BE_USER'] = $this->backendUser(7);

        self::assertTrue($this->guard()->canAccessStoredJob(['created_by' => 7]));
    }

    public function testAllowsAdmin(): void
    {
        $GLOBALS['BE_USER'] = $this->backendUser(12, true);

        self::assertTrue($this->guard()->canAccessStoredJob(['created_by' => 7]));
    }

    public function testDeniesDifferentBackendUser(): void
    {
        $GLOBALS['BE_USER'] = $this->backendUser(8);

        self::assertFalse($this->guard()->canAccessStoredJob(['created_by' => 7]));
    }

    public function testDeniesMissingBackendUser(): void
    {
        self::assertFalse($this->guard()->canAccessStoredJob(['created_by' => 7]));
    }

    private function guard(): BatchJobAccessGuard
    {
        $reflection = new \ReflectionClass(BatchJobAccessGuard::class);

        return $reflection->newInstanceWithoutConstructor();
    }

    private function backendUser(int $uid, bool $admin = false): BackendUserAuthentication
    {
        $reflection = new \ReflectionClass(BackendUserAuthentication::class);
        /** @var BackendUserAuthentication $backendUser */
        $backendUser = $reflection->newInstanceWithoutConstructor();
        $backendUser->user = [
            'uid' => $uid,
            'admin' => $admin ? 1 : 0,
        ];

        return $backendUser;
    }
}
