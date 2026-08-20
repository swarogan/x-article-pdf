<?php

declare(strict_types=1);

namespace XArticlePdf\Tests;

use PHPUnit\Framework\TestCase;
use XArticlePdf\TextChunks;

final class TextChunksTest extends TestCase
{
    public function testKeepsShortTextWhole(): void
    {
        $this->assertSame(['hello'], TextChunks::split('hello', 100));
    }

    public function testSplitsOnParagraphsThenSentences(): void
    {
        $a = str_repeat('Alpha sentence. ', 20);
        $b = str_repeat('Bravo sentence. ', 20);
        $text = trim($a) . "\n\n" . trim($b);
        $parts = TextChunks::split($text, 80);
        $this->assertGreaterThan(1, count($parts));
        foreach ($parts as $part) {
            $this->assertLessThanOrEqual(80, mb_strlen($part));
        }
        $this->assertStringContainsString('Alpha', $parts[0]);
        $this->assertStringContainsString('Bravo', implode(' ', $parts));
    }
}
