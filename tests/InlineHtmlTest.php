<?php

declare(strict_types=1);

namespace XArticlePdf\Tests;

use PHPUnit\Framework\TestCase;
use XArticlePdf\InlineHtml;

final class InlineHtmlTest extends TestCase
{
    public function testEscapesStrayLtAndDropsDocumentEndTags(): void
    {
        $html = InlineHtml::sanitize('Alpha 5 < 6</html></body><h2>Nope</h2> still <a href="https://x.com/a">X</a>');
        $this->assertStringContainsString('5 &lt; 6', $html);
        $this->assertStringContainsString('still <a href="https://x.com/a">X</a>', $html);
        $this->assertStringNotContainsString('</html>', $html);
        $this->assertStringNotContainsString('<h2>', $html);
    }

    public function testClosesUnclosedAnchors(): void
    {
        $html = InlineHtml::sanitize('<a href="https://example.com">click');
        $this->assertSame('<a href="https://example.com">click</a>', $html);
    }
}
