<?php

declare(strict_types=1);

namespace XArticlePdf\Tests;

use PHPUnit\Framework\TestCase;
use XArticlePdf\ArticleDocument;
use XArticlePdf\Author;
use XArticlePdf\HtmlRenderer;
use XArticlePdf\MediaStore;

final class HtmlRendererTest extends TestCase
{
    public function testRendersTitleAuthorAndImageFromLocalStore(): void
    {
        $dir = sys_get_temp_dir() . '/xpdf-test-' . bin2hex(random_bytes(4));
        $store = new class ($dir) extends MediaStore {
            public function localPath(string $url): ?string
            {
                return $url === '' ? null : '/tmp/fake-image.jpg';
            }
        };

        $html = (new HtmlRenderer($store))->render(new ArticleDocument(
            id: '1',
            url: 'https://x.com/anna/status/1',
            title: 'Hello & goodbye',
            author: new Author('Anna', 'anna', 'https://pbs.twimg.com/a.jpg', 'https://x.com/anna'),
            publishedAt: '2026-08-19 16:44',
            coverUrl: 'https://pbs.twimg.com/cover.jpg',
            blocks: [
                ['type' => 'paragraph', 'html' => 'Treść'],
                ['type' => 'image', 'url' => 'https://pbs.twimg.com/pic.jpg'],
            ],
            isLongArticle: true,
        ));

        $this->assertStringContainsString('Hello &amp; goodbye', $html);
        $this->assertStringContainsString('@anna', $html);
        $this->assertStringContainsString('Artykuł X', $html);
        $this->assertStringContainsString('/tmp/fake-image.jpg', $html);
        $this->assertStringContainsString('Treść', $html);
    }

    public function testRendersMarkdownAndRewritesImages(): void
    {
        $dir = sys_get_temp_dir() . '/xpdf-test-' . bin2hex(random_bytes(4));
        $store = new class ($dir) extends MediaStore {
            public function localPath(string $url): ?string
            {
                return '/tmp/from-' . basename(parse_url($url, PHP_URL_PATH) ?: 'x.jpg');
            }
        };

        $html = (new HtmlRenderer($store))->render(new ArticleDocument(
            id: '1',
            url: 'https://x.com/anna/status/1',
            title: 'Md',
            author: new Author('Anna', 'anna', null, 'https://x.com/anna'),
            publishedAt: null,
            coverUrl: null,
            blocks: [
                ['type' => 'markdown', 'markdown' => "## Sekcja\n\nTekst **pogrubiony** i obraz:\n\n![alt](https://pbs.twimg.com/media/pic.jpg)"],
            ],
            isLongArticle: true,
        ));

        $this->assertStringContainsString('<h2>Sekcja</h2>', $html);
        $this->assertStringContainsString('<strong>pogrubiony</strong>', $html);
        $this->assertStringContainsString('/tmp/from-pic.jpg', $html);
        $this->assertStringNotContainsString('https://pbs.twimg.com/media/pic.jpg', $html);
        $html2 = (new HtmlRenderer($store))->render(new ArticleDocument(
            id: '2',
            url: 'https://x.com/anna/status/2',
            title: 'Code',
            author: new Author('Anna', 'anna', null, 'https://x.com/anna'),
            publishedAt: null,
            coverUrl: null,
            blocks: [
                ['type' => 'code', 'code' => "echo 1;\necho 2;"],
                ['type' => 'divider'],
            ],
            isLongArticle: true,
        ));
        $card = (new HtmlRenderer($store))->render(new ArticleDocument(
            id: '3',
            url: 'https://x.com/anna/status/3',
            title: 'Card',
            author: new Author('Anna', 'anna', null, 'https://x.com/anna'),
            publishedAt: null,
            coverUrl: null,
            blocks: [
                ['type' => 'md-card', 'markdown' => "Own weekly prospecting for [what you sell]."],
            ],
            isLongArticle: true,
        ));
        $this->assertStringContainsString('bgcolor="#F1F5F9"', $card);
        $this->assertStringContainsString('[what you sell]', $card);
        $mediaHtml = (new HtmlRenderer($store))->render(new ArticleDocument(
            id: '4',
            url: 'https://x.com/anna/status/4',
            title: 'Film',
            author: new Author('Anna', 'anna', null, 'https://x.com/anna'),
            publishedAt: null,
            coverUrl: null,
            blocks: [
                ['type' => 'image', 'url' => 'https://pbs.twimg.com/media/pic.jpg', 'caption' => 'Four sentences. The last one is the fence.'],
                [
                    'type' => 'video',
                    'url' => 'https://video.twimg.com/x.mp4',
                    'pageUrl' => 'https://x.com/anna/status/4',
                    'previewUrl' => 'https://pbs.twimg.com/media/thumb.jpg',
                    'caption' => 'The bot is driving the browser.',
                    'durationMs' => 39000,
                ],
            ],
            isLongArticle: true,
        ));
        $this->assertStringContainsString('Four sentences. The last one is the fence.', $mediaHtml);
        $this->assertStringContainsString('The bot is driving the browser.', $mediaHtml);
        $this->assertStringNotContainsString('Obejrzyj na X', $mediaHtml);
        $this->assertStringContainsString('Video (0:39):', $mediaHtml);
        $this->assertStringContainsString('>https://video.twimg.com/x.mp4<', $mediaHtml);
        $this->assertStringContainsString('echo 1;', $html2);
        $this->assertStringContainsString('class="codeblock"', $html2);
        $this->assertStringContainsString('<br', $html2);
        $this->assertStringContainsString('<hr class="div"', $html2);
    }
}
