<?php

declare(strict_types=1);

namespace XArticlePdf\Tests;

use PHPUnit\Framework\TestCase;
use XArticlePdf\ArticleDocument;
use XArticlePdf\Author;
use XArticlePdf\MarkdownExporter;

final class MarkdownExporterTest extends TestCase
{
    public function testExportsHeadingsImagesVideosAndCode(): void
    {
        $md = (new MarkdownExporter())->build(new ArticleDocument(
            id: '90',
            url: 'https://x.com/anna/status/90',
            title: 'Tytuł',
            author: new Author('Anna', 'anna', null, 'https://x.com/anna'),
            publishedAt: '2026-08-19 16:44',
            coverUrl: null,
            blocks: [
                ['type' => 'heading', 'level' => 2, 'html' => 'Rozdział'],
                ['type' => 'paragraph', 'html' => 'Ala ma <strong>kota</strong>'],
                ['type' => 'image', 'url' => 'https://pbs.twimg.com/media/one.jpg', 'caption' => 'Four sentences'],
                [
                    'type' => 'video',
                    'url' => 'https://video.twimg.com/x.mp4',
                    'previewUrl' => 'https://pbs.twimg.com/media/thumb.jpg',
                    'caption' => 'The bot is driving',
                    'durationMs' => 39000,
                ],
                ['type' => 'code', 'code' => "echo 1;"],
                ['type' => 'md-card', 'markdown' => 'Own weekly prospecting for [what you sell].'],
                ['type' => 'li', 'ordered' => false, 'html' => 'pierwszy'],
                ['type' => 'li', 'ordered' => false, 'html' => 'drugi'],
            ],
            isLongArticle: true,
        ));

        $this->assertStringContainsString('# Tytuł', $md);
        $this->assertStringContainsString('Anna (@anna) · 2026-08-19 16:44', $md);
        $this->assertStringContainsString('## Rozdział', $md);
        $this->assertStringContainsString('Ala ma **kota**', $md);
        $this->assertStringContainsString('![Four sentences](https://pbs.twimg.com/media/one.jpg)', $md);
        $this->assertStringContainsString('<video src="https://video.twimg.com/x.mp4" poster="https://pbs.twimg.com/media/thumb.jpg" controls></video>', $md);
        $this->assertStringContainsString('https://video.twimg.com/x.mp4', $md);
        $this->assertStringContainsString('*The bot is driving*', $md);
        $this->assertStringContainsString("```\necho 1;\n```", $md);
        $this->assertStringContainsString('Own weekly prospecting for [what you sell].', $md);
        $this->assertStringContainsString("- pierwszy\n- drugi", $md);
        $this->assertStringContainsString('Źródło: https://x.com/anna/status/90', $md);
        $this->assertSame('anna-90.md', MarkdownExporter::filename(new ArticleDocument(
            id: '90',
            url: 'https://x.com/anna/status/90',
            title: 'Tytuł',
            author: new Author('Anna', 'anna', null, 'https://x.com/anna'),
            publishedAt: null,
            coverUrl: null,
            blocks: [],
            isLongArticle: true,
        )));
    }
}
