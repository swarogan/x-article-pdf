<?php

declare(strict_types=1);

namespace XArticlePdf\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XArticlePdf\XUrlParser;

final class XUrlParserTest extends TestCase
{
    #[DataProvider('validUrls')]
    public function testParsesSupportedUrls(string $url, string $kind, string $id, ?string $handle): void
    {
        $parsed = XUrlParser::parse($url);
        $this->assertNotNull($parsed);
        $this->assertSame($kind, $parsed->kind);
        $this->assertSame($id, $parsed->id);
        $this->assertSame($handle, $parsed->handle);
    }

    /**
     * @return list<array{string, string, string, ?string}>
     */
    public static function validUrls(): array
    {
        return [
            ['https://x.com/FabrizioRomano/status/2090117830263935452', 'status', '2090117830263935452', 'FabrizioRomano'],
            ['https://twitter.com/user/status/1', 'status', '1', 'user'],
            ['https://x.com/i/web/status/99', 'status', '99', null],
            ['https://x.com/i/article/2007138660165009408', 'article', '2007138660165009408', null],
            ['x.com/a/status/42', 'status', '42', 'a'],
            ['https://fxtwitter.com/foo/status/7', 'status', '7', 'foo'],
        ];
    }

    public function testRejectsForeignHosts(): void
    {
        $this->assertNull(XUrlParser::parse('https://example.com/status/1'));
        $this->assertNull(XUrlParser::parse('not a url'));
        $this->assertNull(XUrlParser::parse(''));
    }
}
