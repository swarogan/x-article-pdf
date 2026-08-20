<?php

declare(strict_types=1);

namespace XArticlePdf\Tests;

use PHPUnit\Framework\TestCase;
use XArticlePdf\ArticleDocument;
use XArticlePdf\Author;
use XArticlePdf\DocumentTranslator;
use XArticlePdf\Translator;

final class DocumentTranslatorTest extends TestCase
{
    public function testTranslatesTitleCaptionAndHtmlButSkipsCode(): void
    {
        $doc = new ArticleDocument(
            id: '1',
            url: 'https://x.com/anna/status/1',
            title: 'Hello world',
            author: new Author('Anna', 'anna', null, 'https://x.com/anna'),
            publishedAt: '2026-08-19',
            coverUrl: null,
            blocks: [
                ['type' => 'paragraph', 'html' => 'Mara is the prospector'],
                ['type' => 'image', 'url' => 'https://pbs.twimg.com/x.jpg', 'caption' => 'Four sentences'],
                ['type' => 'code', 'code' => 'echo 1;'],
                ['type' => 'md-card', 'markdown' => 'Own weekly prospecting'],
            ],
            isLongArticle: true,
        );

        $out = (new DocumentTranslator(new PrefixTranslator()))->translate($doc, 'Polish');
        $this->assertSame('PL:Hello world', $out->title);
        $this->assertSame('PL:Mara is the prospector', $out->blocks[0]['html']);
        $this->assertSame('PL:Four sentences', $out->blocks[1]['caption']);
        $this->assertSame('echo 1;', $out->blocks[2]['code']);
        $this->assertSame('PL:Own weekly prospecting', $out->blocks[3]['markdown']);
        $this->assertSame('https://x.com/anna/status/1', $out->url);
        $this->assertSame('anna', $out->author->handle);
    }
}

final class PrefixTranslator implements Translator
{
    public function translate(array $texts, string $targetLanguage, ?callable $onProgress = null): array
    {
        $out = [];
        foreach ($texts as $text) {
            $out[] = 'PL:' . $text;
        }

        return $out;
    }
}
