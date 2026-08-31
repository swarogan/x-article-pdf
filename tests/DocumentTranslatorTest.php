<?php

declare(strict_types=1);

namespace XArticlePdf\Tests;

use PHPUnit\Framework\TestCase;
use XArticlePdf\ArticleDocument;
use XArticlePdf\Author;
use XArticlePdf\DocumentTranslator;
use XArticlePdf\JobCancelledException;
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

    public function testSanitizesTranslatedHtmlSoDocumentTagsCannotCutThePdf(): void
    {
        $doc = new ArticleDocument(
            id: '1',
            url: 'https://x.com/anna/status/1',
            title: 'Hello world',
            author: new Author('Anna', 'anna', null, 'https://x.com/anna'),
            publishedAt: null,
            coverUrl: null,
            blocks: [
                ['type' => 'paragraph', 'html' => 'Keep going'],
            ],
            isLongArticle: true,
        );
        $out = (new DocumentTranslator(new class implements Translator {
            public function translate(array $texts, string $targetLanguage, ?callable $onProgress = null): array
            {
                return ['T', 'Part </html><script>x</script> 3 < 4 <a href="https://x.com/a">A'];
            }
        }))->translate($doc, 'Polish');
        $this->assertSame('T', $out->title);
        $this->assertStringContainsString('3 &lt; 4', $out->blocks[0]['html']);
        $this->assertStringContainsString('<a href="https://x.com/a">A</a>', $out->blocks[0]['html']);
        $this->assertStringNotContainsString('</html>', $out->blocks[0]['html']);
        $this->assertStringNotContainsString('<script>', $out->blocks[0]['html']);
    }

    public function testRecordsTargetLanguageAndModelOnTranslatedDocument(): void
    {
        $doc = new ArticleDocument(
            id: '1',
            url: 'https://x.com/anna/status/1',
            title: 'Hello world',
            author: new Author('Anna', 'anna', null, 'https://x.com/anna'),
            publishedAt: null,
            coverUrl: null,
            blocks: [['type' => 'paragraph', 'html' => 'Keep going']],
            isLongArticle: true,
        );
        $out = (new DocumentTranslator(new PrefixTranslator()))->translate($doc, 'Polish', null, 'gemma4:e2b');
        $this->assertSame('Polish', $out->translatedTo);
        $this->assertSame('gemma4:e2b', $out->translationModel);
        $this->assertNull($doc->translatedTo);
    }

    public function testLeavesMetadataEmptyWhenNothingWasTranslated(): void
    {
        $doc = new ArticleDocument(
            id: '1',
            url: 'https://x.com/anna/status/1',
            title: '...',
            author: new Author('Anna', 'anna', null, 'https://x.com/anna'),
            publishedAt: null,
            coverUrl: null,
            blocks: [['type' => 'code', 'code' => 'echo 1;']],
            isLongArticle: false,
        );
        $out = (new DocumentTranslator(new PrefixTranslator()))->translate($doc, 'Polish', null, 'gemma4:e2b');
        $this->assertNull($out->translatedTo);
        $this->assertNull($out->translationModel);
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

    public function testStopFromProgressCallbackAbortsTranslationInsteadOfBeingRetried(): void
    {
        $doc = new ArticleDocument(
            id: '1',
            url: 'https://x.com/anna/status/1',
            title: 'Hello world',
            author: new Author('Anna', 'anna', null, 'https://x.com/anna'),
            publishedAt: null,
            coverUrl: null,
            blocks: [
                ['type' => 'paragraph', 'html' => 'First paragraph'],
                ['type' => 'paragraph', 'html' => 'Second paragraph'],
            ],
            isLongArticle: true,
        );
        $translator = new CountingTranslator();

        $this->expectException(JobCancelledException::class);
        (new DocumentTranslator($translator))->translate($doc, 'Polish', static function (): void {
            throw new JobCancelledException('Przerwano.');
        });
    }
}

final class CountingTranslator implements Translator
{
    public int $calls = 0;

    public function translate(array $texts, string $targetLanguage, ?callable $onProgress = null): array
    {
        $out = [];
        foreach ($texts as $i => $text) {
            $this->calls++;
            if ($onProgress !== null) {
                $onProgress($i + 1, count($texts));
            }
            $out[] = 'PL:' . $text;
        }

        return $out;
    }
}
