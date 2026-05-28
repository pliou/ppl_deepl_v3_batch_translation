<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Tests\Unit\Packaging;

use PHPUnit\Framework\TestCase;

final class PublicAssetPresenceTest extends TestCase
{
    public function testLoadedBackendAssetsExist(): void
    {
        $extensionRoot = dirname(__DIR__, 3);

        self::assertFileExists($extensionRoot . '/Resources/Public/Css/backend.css');
        self::assertFileExists($extensionRoot . '/Resources/Public/Javascript/backend-scroll.js');
    }
}
