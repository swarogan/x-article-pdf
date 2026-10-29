<?php

declare(strict_types=1);

namespace XArticlePdf\Tests;

use PHPUnit\Framework\TestCase;
use XArticlePdf\ArticleDocument;
use XArticlePdf\Author;
use XArticlePdf\FileName;
use XArticlePdf\MarkdownExporter;
use XArticlePdf\PdfExporter;

/**
 * Pobrany plik ma się nazywać od tytułu artykułu, nie od numeru tweeta.
 */
final class FileNameTest extends TestCase
{
    private function doc(string $title, string $handle = 'anna', string $id = '90'): ArticleDocument
    {
        return new ArticleDocument(
            id: $id,
            url: 'https://x.com/' . $handle . '/status/' . $id,
            title: $title,
            author: new Author('Anna', $handle, null, 'https://x.com/' . $handle),
            publishedAt: null,
            coverUrl: null,
            blocks: [],
            isLongArticle: true,
        );
    }

    public function testPdfAndMarkdownAreNamedAfterTheTitle(): void
    {
        $doc = $this->doc('Jak zbudować własną grę w Unreal Engine');

        $this->assertSame('jak-zbudowac-wlasna-gre-w-unreal-engine.pdf', PdfExporter::filename($doc));
        $this->assertSame('jak-zbudowac-wlasna-gre-w-unreal-engine.md', MarkdownExporter::filename($doc));
    }

    public function testPolishLettersAreTransliteratedNotDropped(): void
    {
        $this->assertSame('zolc-gesla-jazn', FileName::base($this->doc('Żółć gęślą jaźń')));
        $this->assertSame('lodz-czy-lodz', FileName::base($this->doc('Łódź czy łódź')));
    }

    public function testPunctuationCollapsesToSingleHyphens(): void
    {
        $this->assertSame(
            'grok-bot-jak-zbudowac-swoj-pierwszy-zespol-ai-pelny-przewodnik',
            FileName::base($this->doc('Grok Bot: Jak zbudować swój pierwszy zespół AI (Pełny przewodnik)')),
        );
        $this->assertSame('a-b', FileName::base($this->doc('  --a ??? b--  ')));
    }

    public function testLongTitleIsCutAtAWordBoundary(): void
    {
        $title = str_repeat('słowo ', 40);
        $base = FileName::base($this->doc($title));

        $this->assertLessThanOrEqual(80, strlen($base));
        $this->assertStringEndsNotWith('-', $base);
        $this->assertStringEndsWith('slowo', $base, 'Cięcie nie może zostawić połowy słowa.');
    }

    public function testTitleWithoutLatinLettersFallsBackToHandleAndId(): void
    {
        // pusty slug dałby plik ".pdf" — zostaje stara, jednoznaczna nazwa
        $this->assertSame('anna-90', FileName::base($this->doc('日本語のタイトル')));
        $this->assertSame('anna-90', FileName::base($this->doc('???')));
        $this->assertSame('anna-90.pdf', PdfExporter::filename($this->doc('')));
    }

    public function testNameIsSafeInAContentDispositionHeader(): void
    {
        $name = PdfExporter::filename($this->doc("Zły\"tytuł\r\nX-Evil: 1/../etc"));

        $this->assertMatchesRegularExpression('/^[a-z0-9-]+\.pdf$/', $name);
    }
}
