<?php

declare(strict_types=1);

namespace XArticlePdf\Tests;

use PHPUnit\Framework\TestCase;
use XArticlePdf\DocumentBuilder;
use XArticlePdf\ParsedXUrl;
use XArticlePdf\TwitterSource;

final class DocumentBuilderTest extends TestCase
{
    public function testBuildsThreadFromStatuses(): void
    {
        $source = new ArrayTwitterSource([
            'status:10' => [
                'code' => 200,
                'status' => $this->tweetPayload('10', 'Pierwszy akapit', ['https://pbs.twimg.com/media/a.jpg']),
                'thread' => null,
                'author' => $this->author(),
            ],
            'thread:10' => [
                'code' => 200,
                'status' => $this->tweetPayload('10', 'Pierwszy akapit', ['https://pbs.twimg.com/media/a.jpg']),
                'thread' => [
                    $this->tweetPayload('10', 'Pierwszy akapit', ['https://pbs.twimg.com/media/a.jpg']),
                    $this->tweetPayload('11', 'Drugi akapit', []),
                ],
                'author' => $this->author(),
            ],
        ]);

        $doc = (new DocumentBuilder($source))->fromParsedUrl(new ParsedXUrl('status', '10', 'anna'));
        $this->assertFalse($doc->isLongArticle);
        $this->assertSame('Pierwszy akapit', $doc->title);
        $this->assertSame('Anna', $doc->author->name);
        $types = array_column($doc->blocks, 'type');
        $this->assertSame(['paragraph', 'image', 'paragraph'], $types);
    }

    public function testBuildsLongArticleFromDraftBlocks(): void
    {
        $source = new ArrayTwitterSource([
            'status:20' => [
                'code' => 200,
                'author' => $this->author(),
                'status' => [
                    'id' => '20',
                    'url' => 'https://x.com/anna/status/20',
                    'text' => '',
                    'author' => $this->author(),
                    'created_at' => 'Wed Aug 19 16:44:57 +0000 2026',
                    'article' => [
                        'title' => 'Tytuł artykułu',
                        'created_at' => 'Wed Aug 19 16:44:57 +0000 2026',
                        'cover_media' => [
                            'media_info' => [
                                'original_img_url' => 'https://pbs.twimg.com/media/cover.jpg',
                            ],
                        ],
                        'content' => [
                            'blocks' => [
                                ['type' => 'header-two', 'text' => 'Rozdział', 'inlineStyleRanges' => [], 'entityRanges' => []],
                                ['type' => 'unstyled', 'text' => 'Hello', 'inlineStyleRanges' => [['offset' => 0, 'length' => 5, 'style' => 'Bold']], 'entityRanges' => []],
                                ['type' => 'atomic', 'text' => ' ', 'entityRanges' => [['key' => 0, 'length' => 1, 'offset' => 0]]],
                            ],
                            'entityMap' => [
                                ['key' => '0', 'value' => ['type' => 'TWEET', 'data' => ['tweetId' => '99']]],
                            ],
                        ],
                    ],
                ],
            ],
            'status:99' => [
                'code' => 200,
                'status' => $this->tweetPayload('99', 'Osadzony tweet', ['https://pbs.twimg.com/media/b.jpg']),
                'author' => $this->author(),
            ],
        ]);

        $doc = (new DocumentBuilder($source))->fromParsedUrl(new ParsedXUrl('status', '20', 'anna'));
        $this->assertTrue($doc->isLongArticle);
        $this->assertSame('Tytuł artykułu', $doc->title);
        $this->assertSame('https://pbs.twimg.com/media/cover.jpg', $doc->coverUrl);
        $this->assertSame('heading', $doc->blocks[0]['type']);
        $this->assertSame('<strong>Hello</strong>', $doc->blocks[1]['html']);
        $this->assertSame('tweet', $doc->blocks[2]['type']);
        $this->assertSame('Osadzony tweet', $doc->blocks[2]['text']);
        $this->assertSame(['https://pbs.twimg.com/media/b.jpg'], $doc->blocks[2]['photos']);
    }

    public function testKeepsNewlinesAndTreatsListsAsMarkdown(): void
    {
        $source = new ArrayTwitterSource([
            'status:40' => [
                'code' => 200,
                'author' => $this->author(),
                'status' => [
                    'id' => '40',
                    'url' => 'https://x.com/anna/status/40',
                    'text' => '',
                    'author' => $this->author(),
                    'created_at' => 'Wed Aug 19 16:44:57 +0000 2026',
                    'article' => [
                        'title' => 'Nowe linie',
                        'content' => [
                            'blocks' => [
                                ['type' => 'unstyled', 'text' => "Groq\n\nDalej treść", 'inlineStyleRanges' => [], 'entityRanges' => []],
                                ['type' => 'unstyled', 'text' => "- jeden\n- dwa", 'inlineStyleRanges' => [], 'entityRanges' => []],
                                ['type' => 'atomic', 'text' => ' ', 'entityRanges' => [['key' => 0, 'length' => 1, 'offset' => 0]]],
                            ],
                            'entityMap' => [
                                ['key' => '0', 'value' => ['type' => 'DIVIDER', 'data' => []]],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $doc = (new DocumentBuilder($source))->fromParsedUrl(new ParsedXUrl('status', '40', 'anna'));
        $this->assertSame('paragraph', $doc->blocks[0]['type']);
        $this->assertStringContainsString('<br', $doc->blocks[0]['html']);
        $this->assertSame('paragraph', $doc->blocks[1]['type']);
        $this->assertStringContainsString('- jeden', $doc->blocks[1]['html']);
        $this->assertSame('divider', $doc->blocks[2]['type']);
    }

    public function testDoesNotConfuseEntityListIndexWithDraftKey(): void
    {
        $source = new ArrayTwitterSource([
            'status:50' => [
                'code' => 200,
                'author' => $this->author(),
                'status' => [
                    'id' => '50',
                    'url' => 'https://x.com/anna/status/50',
                    'text' => '',
                    'author' => $this->author(),
                    'created_at' => 'Wed Aug 19 16:44:57 +0000 2026',
                    'article' => [
                        'title' => 'Kolizja kluczy',
                        'content' => [
                            'blocks' => [
                                ['type' => 'unstyled', 'text' => 'Przed kodem', 'inlineStyleRanges' => [], 'entityRanges' => []],
                                ['type' => 'atomic', 'text' => ' ', 'entityRanges' => [['key' => 12, 'length' => 1, 'offset' => 0]]],
                                ['type' => 'unstyled', 'text' => 'Po kodzie, przed obrazkiem', 'inlineStyleRanges' => [], 'entityRanges' => []],
                                ['type' => 'atomic', 'text' => ' ', 'entityRanges' => [['key' => 1, 'length' => 1, 'offset' => 0]]],
                                ['type' => 'atomic', 'text' => ' ', 'entityRanges' => [['key' => 34, 'length' => 1, 'offset' => 0]]],
                            ],
                            'entityMap' => [
                                ['key' => '34', 'value' => ['type' => 'DIVIDER', 'data' => []]],
                                ['key' => '12', 'value' => ['type' => 'MARKDOWN', 'data' => ['markdown' => 'kod']]],
                                ['key' => '1', 'value' => ['type' => 'MEDIA', 'data' => ['mediaItems' => [['mediaId' => 111]]]]],
                            ],
                        ],
                        'media_entities' => [
                            ['media_id' => 111, 'media_info' => ['original_img_url' => 'https://pbs.twimg.com/media/one.jpg']],
                        ],
                    ],
                ],
            ],
        ]);

        $doc = (new DocumentBuilder($source))->fromParsedUrl(new ParsedXUrl('status', '50', 'anna'));
        $types = array_column($doc->blocks, 'type');
        $this->assertSame(['paragraph', 'markdown', 'paragraph', 'image', 'divider'], $types);
        $this->assertSame('kod', $doc->blocks[1]['markdown']);
        $this->assertSame('https://pbs.twimg.com/media/one.jpg', $doc->blocks[3]['url']);
    }

    public function testImageCaptionAndVideoLink(): void
    {
        $source = new ArrayTwitterSource([
            'status:90' => [
                'code' => 200,
                'author' => $this->author(),
                'status' => [
                    'id' => '90',
                    'url' => 'https://x.com/anna/status/90',
                    'text' => '',
                    'author' => $this->author(),
                    'created_at' => 'Wed Aug 19 16:44:57 +0000 2026',
                    'article' => [
                        'title' => 'Media',
                        'content' => [
                            'blocks' => [
                                ['type' => 'atomic', 'text' => ' ', 'entityRanges' => [['key' => 1, 'length' => 1, 'offset' => 0]]],
                                ['type' => 'atomic', 'text' => ' ', 'entityRanges' => [['key' => 2, 'length' => 1, 'offset' => 0]]],
                            ],
                            'entityMap' => [
                                ['key' => '1', 'value' => [
                                    'type' => 'MEDIA',
                                    'data' => [
                                        'caption' => 'Four sentences. The last one is the fence.',
                                        'mediaItems' => [['mediaId' => '111', 'mediaCategory' => 'DraftTweetImage']],
                                    ],
                                ]],
                                ['key' => '2', 'value' => [
                                    'type' => 'MEDIA',
                                    'data' => [
                                        'caption' => 'The bot is driving the browser.',
                                        'mediaItems' => [['mediaId' => '222', 'mediaCategory' => 'AmplifyVideo']],
                                    ],
                                ]],
                            ],
                        ],
                        'media_entities' => [
                            [
                                'media_id' => '111',
                                'media_info' => [
                                    '__typename' => 'ApiImage',
                                    'original_img_url' => 'https://pbs.twimg.com/media/one.jpg',
                                ],
                            ],
                            [
                                'media_id' => '222',
                                'media_info' => [
                                    '__typename' => 'ApiVideo',
                                    'duration_millis' => 39800,
                                    'preview_image' => [
                                        'original_img_url' => 'https://pbs.twimg.com/amplify_video_thumb/222/img/x.jpg',
                                    ],
                                    'variants' => [
                                        ['content_type' => 'video/mp4', 'bit_rate' => 2176000, 'url' => 'https://video.twimg.com/amplify_video/222/720.mp4'],
                                        ['content_type' => 'video/mp4', 'bit_rate' => 25128000, 'url' => 'https://video.twimg.com/amplify_video/222/4k.mp4'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $doc = (new DocumentBuilder($source))->fromParsedUrl(new ParsedXUrl('status', '90', 'anna'));
        $this->assertSame('image', $doc->blocks[0]['type']);
        $this->assertSame('Four sentences. The last one is the fence.', $doc->blocks[0]['caption']);
        $this->assertSame('video', $doc->blocks[1]['type']);
        $this->assertSame('The bot is driving the browser.', $doc->blocks[1]['caption']);
        $this->assertSame('https://video.twimg.com/amplify_video/222/720.mp4', $doc->blocks[1]['url']);
        $this->assertSame('https://x.com/anna/status/90', $doc->blocks[1]['pageUrl']);
        $this->assertSame(39800, $doc->blocks[1]['durationMs']);
    }

    public function testDraftKeyIsNotOverwrittenByListIndex(): void
    {
        $entities = [];
        for ($i = 0; $i < 13; $i++) {
            $entities[] = ['key' => (string) (100 + $i), 'value' => ['type' => 'DIVIDER', 'data' => []]];
        }
        $entities[1] = ['key' => '12', 'value' => ['type' => 'MARKDOWN', 'data' => ['markdown' => 'na-miejscu-12']]];
        $entities[12] = ['key' => '13', 'value' => ['type' => 'DIVIDER', 'data' => []]];

        $source = new ArrayTwitterSource([
            'status:70' => [
                'code' => 200,
                'author' => $this->author(),
                'status' => [
                    'id' => '70',
                    'url' => 'https://x.com/anna/status/70',
                    'text' => '',
                    'author' => $this->author(),
                    'created_at' => 'Wed Aug 19 16:44:57 +0000 2026',
                    'article' => [
                        'title' => 'Klucz 12',
                        'content' => [
                            'blocks' => [
                                ['type' => 'unstyled', 'text' => 'Sekcja Cloudflare', 'inlineStyleRanges' => [], 'entityRanges' => []],
                                ['type' => 'atomic', 'text' => ' ', 'entityRanges' => [['key' => 12, 'length' => 1, 'offset' => 0]]],
                            ],
                            'entityMap' => $entities,
                        ],
                    ],
                ],
            ],
        ]);

        $doc = (new DocumentBuilder($source))->fromParsedUrl(new ParsedXUrl('status', '70', 'anna'));
        $this->assertSame('paragraph', $doc->blocks[0]['type']);
        $this->assertSame('markdown', $doc->blocks[1]['type']);
        $this->assertSame('na-miejscu-12', $doc->blocks[1]['markdown']);
    }

    public function testSplitsMarkdownFencesIntoCodeBlocks(): void
    {
        $source = new ArrayTwitterSource([
            'status:60' => [
                'code' => 200,
                'author' => $this->author(),
                'status' => [
                    'id' => '60',
                    'url' => 'https://x.com/anna/status/60',
                    'text' => '',
                    'author' => $this->author(),
                    'created_at' => 'Wed Aug 19 16:44:57 +0000 2026',
                    'article' => [
                        'title' => 'Kod',
                        'content' => [
                            'blocks' => [
                                ['type' => 'atomic', 'text' => ' ', 'entityRanges' => [['key' => 0, 'length' => 1, 'offset' => 0]]],
                            ],
                            'entityMap' => [
                                ['key' => '0', 'value' => [
                                    'type' => 'MARKDOWN',
                                    'data' => ['markdown' => "Intro\n\n```python\nprint(1)\n```\n\nOutro"],
                                ]],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $doc = (new DocumentBuilder($source))->fromParsedUrl(new ParsedXUrl('status', '60', 'anna'));
        $this->assertSame('markdown', $doc->blocks[0]['type']);
        $this->assertSame('Intro', $doc->blocks[0]['markdown']);
        $this->assertSame('code', $doc->blocks[1]['type']);
        $this->assertSame('print(1)', $doc->blocks[1]['code']);
        $this->assertSame('markdown', $doc->blocks[2]['type']);
        $this->assertSame('Outro', $doc->blocks[2]['markdown']);
    }

    public function testMarkdownFenceBecomesCardNotRawCode(): void
    {
        $source = new ArrayTwitterSource([
            'status:80' => [
                'code' => 200,
                'author' => $this->author(),
                'status' => [
                    'id' => '80',
                    'url' => 'https://x.com/anna/status/80',
                    'text' => '',
                    'author' => $this->author(),
                    'created_at' => 'Wed Aug 19 16:44:57 +0000 2026',
                    'article' => [
                        'title' => 'Mara',
                        'content' => [
                            'blocks' => [
                                ['type' => 'unstyled', 'text' => 'Mara is the prospector, and hers is built to that shape:', 'inlineStyleRanges' => [], 'entityRanges' => []],
                                ['type' => 'atomic', 'text' => ' ', 'entityRanges' => [['key' => 8, 'length' => 1, 'offset' => 0]]],
                            ],
                            'entityMap' => [
                                ['key' => '8', 'value' => [
                                    'type' => 'MARKDOWN',
                                    'data' => [
                                        'markdown' => "```markdown\nOwn weekly prospecting for [what you sell]. Pull names from [sources],\ndrop anyone already in a sequence.\n\n```",
                                    ],
                                ]],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $doc = (new DocumentBuilder($source))->fromParsedUrl(new ParsedXUrl('status', '80', 'anna'));
        $this->assertSame('paragraph', $doc->blocks[0]['type']);
        $this->assertSame('md-card', $doc->blocks[1]['type']);
        $this->assertStringContainsString('Own weekly prospecting', $doc->blocks[1]['markdown']);
        $this->assertStringNotContainsString('```', $doc->blocks[1]['markdown']);
    }

    public function testResolvesMarkdownAndMediaEntities(): void
    {
        $source = new ArrayTwitterSource([
            'status:30' => [
                'code' => 200,
                'author' => $this->author(),
                'status' => [
                    'id' => '30',
                    'url' => 'https://x.com/anna/status/30',
                    'text' => '',
                    'author' => $this->author(),
                    'created_at' => 'Wed Aug 19 16:44:57 +0000 2026',
                    'article' => [
                        'title' => 'Z markdownem',
                        'content' => [
                            'blocks' => [
                                ['type' => 'atomic', 'text' => ' ', 'entityRanges' => [['key' => 0, 'length' => 1, 'offset' => 0]]],
                                ['type' => 'atomic', 'text' => ' ', 'entityRanges' => [['key' => 1, 'length' => 1, 'offset' => 0]]],
                            ],
                            'entityMap' => [
                                ['key' => '8', 'value' => [
                                    'type' => 'MARKDOWN',
                                    'data' => [
                                        'entityKey' => 'abc',
                                        'markdown' => "## Nagłówek\n\n- punkt **A**\n- punkt B",
                                    ],
                                ]],
                                ['key' => '3', 'value' => [
                                    'type' => 'MEDIA',
                                    'data' => [
                                        'entityKey' => 'def',
                                        'mediaItems' => [
                                            ['localMediaId' => 'l1', 'mediaCategory' => 'TweetImage', 'mediaId' => '111'],
                                            ['localMediaId' => 'l2', 'mediaCategory' => 'TweetImage', 'mediaId' => '222'],
                                        ],
                                    ],
                                ]],
                            ],
                        ],
                        'media_entities' => [
                            [
                                'media_id' => '111',
                                'media_info' => ['__typename' => 'ApiImage', 'original_img_url' => 'https://pbs.twimg.com/media/one.jpg'],
                            ],
                            [
                                'media_id' => '222',
                                'media_info' => ['__typename' => 'ApiImage', 'original_img_url' => 'https://pbs.twimg.com/media/two.png'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $doc = (new DocumentBuilder($source))->fromParsedUrl(new ParsedXUrl('status', '30', 'anna'));
        $this->assertSame('markdown', $doc->blocks[0]['type']);
        $this->assertStringContainsString('Nagłówek', $doc->blocks[0]['markdown']);
        $this->assertSame('image', $doc->blocks[1]['type']);
        $this->assertSame('https://pbs.twimg.com/media/one.jpg', $doc->blocks[1]['url']);
        $this->assertSame('https://pbs.twimg.com/media/two.png', $doc->blocks[2]['url']);
    }

    public function testArticleUrlIsRejected(): void
    {
        $this->expectExceptionMessage('/i/article/');
        (new DocumentBuilder(new ArrayTwitterSource([])))
            ->fromParsedUrl(new ParsedXUrl('article', '1'));
    }

    /**
     * @return array<string, mixed>
     */
    private function author(): array
    {
        return [
            'name' => 'Anna',
            'screen_name' => 'anna',
            'avatar_url' => 'https://pbs.twimg.com/profile_images/a.jpg',
            'url' => 'https://x.com/anna',
        ];
    }

    /**
     * @param list<string> $photos
     * @return array<string, mixed>
     */
    private function tweetPayload(string $id, string $text, array $photos): array
    {
        $photoObjs = [];
        foreach ($photos as $url) {
            $photoObjs[] = ['type' => 'photo', 'url' => $url];
        }

        return [
            'type' => 'status',
            'id' => $id,
            'url' => 'https://x.com/anna/status/' . $id,
            'text' => $text,
            'author' => $this->author(),
            'created_at' => 'Wed Aug 19 16:44:57 +0000 2026',
            'media' => ['photos' => $photoObjs, 'all' => $photoObjs],
        ];
    }
}

final class ArrayTwitterSource implements TwitterSource
{
    /**
     * @param array<string, array<string, mixed>> $payloads
     */
    public function __construct(private array $payloads)
    {
    }

    public function status(string $id): array
    {
        return $this->payloads['status:' . $id] ?? ['code' => 404];
    }

    public function thread(string $id): array
    {
        return $this->payloads['thread:' . $id] ?? ['code' => 404];
    }
}
