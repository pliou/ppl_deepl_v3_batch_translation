<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Tests\Unit\Domain\Dto;

use PHPUnit\Framework\TestCase;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\PermissionResult;
use Ppl\PplDeeplV3BatchTranslation\Domain\Dto\PreflightItem;

final class PreflightItemTest extends TestCase
{
    public function testBaseUidFallsBackToSourceUidForOldStoredItems(): void
    {
        $item = PreflightItem::fromArray([
            'itemId' => 'pages:42',
            'itemType' => 'page',
            'table' => 'pages',
            'sourceUid' => 42,
            'targetUid' => 0,
            'sourcePageUid' => 42,
            'label' => 'Page 42',
            'status' => 'missing',
            'recordAction' => 'create',
            'permission' => PermissionResult::allowed()->toArray(),
        ]);

        self::assertSame(42, $item->effectiveBaseUid());
        self::assertSame(42, $item->toArray()['baseUid']);
    }

    public function testExplicitBaseUidIsPreserved(): void
    {
        $item = PreflightItem::fromArray([
            'itemId' => 'pages:42',
            'itemType' => 'page',
            'table' => 'pages',
            'baseUid' => 42,
            'sourceUid' => 99,
            'targetUid' => 0,
            'sourcePageUid' => 42,
            'label' => 'Page 42',
            'status' => 'missing',
            'recordAction' => 'create',
            'permission' => PermissionResult::allowed()->toArray(),
        ]);

        self::assertSame(42, $item->effectiveBaseUid());
        self::assertSame(99, $item->sourceUid);
    }
}
