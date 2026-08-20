<?php

declare(strict_types=1);

namespace XArticlePdf\Tests;

use PHPUnit\Framework\TestCase;
use XArticlePdf\LanguageCatalog;

final class LanguageCatalogTest extends TestCase
{
    public function testResolveOffAndKnownAndCustom(): void
    {
        $this->assertNull(LanguageCatalog::resolve(''));
        $this->assertNull(LanguageCatalog::resolve('off'));
        $this->assertSame('Polish', LanguageCatalog::resolve('Polish'));
        $this->assertSame('Japanese', LanguageCatalog::resolve('__custom__', 'Japanese'));
        $this->assertSame('Latin', LanguageCatalog::resolve('__custom__', 'Latin'));
        $this->assertNull(LanguageCatalog::resolve('__custom__', ''));
        $this->assertFalse(LanguageCatalog::isValidName('no<script>'));
    }
}
