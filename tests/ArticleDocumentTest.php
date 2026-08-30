<?php

declare(strict_types=1);

namespace XArticlePdf\Tests;

use PHPUnit\Framework\TestCase;
use XArticlePdf\ArticleDocument;
use XArticlePdf\Author;

final class ArticleDocumentTest extends TestCase
{
    public function testRoundTripArrayKeepsBlocksAndAuthor(): void
    {
        $doc = new ArticleDocument(
            id: '1',
            url: 'https://x.com/a/status/1',
            title: 'T',
            author: new Author('Ada', 'ada', 'https://pbs.twimg.com/a.jpg', 'https://x.com/ada'),
            publishedAt: '2026-08-21',
            coverUrl: 'https://pbs.twimg.com/c.jpg',
            blocks: [['type' => 'heading', 'level' => 2, 'html' => 'H']],
            isLongArticle: true,
        );
        $copy = ArticleDocument::fromArray($doc->toArray());
        $this->assertNotNull($copy);
        $this->assertSame($doc->title, $copy->title);
        $this->assertSame($doc->author->avatarUrl, $copy->author->avatarUrl);
        $this->assertSame($doc->blocks, $copy->blocks);
        $this->assertTrue($copy->isLongArticle);
        $this->assertNull(ArticleDocument::fromArray(['id' => '1']));
    }
}
