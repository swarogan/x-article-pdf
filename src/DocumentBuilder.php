<?php

declare(strict_types=1);

namespace XArticlePdf;

final class DocumentBuilder
{
    public function __construct(private readonly TwitterSource $client)
    {
    }

    public function fromParsedUrl(ParsedXUrl $parsed): ArticleDocument
    {
        if ($parsed->kind === 'article') {
            throw new FetchException(
                'Link /i/article/… nie wystarcza — wklej URL posta (x.com/użytkownik/status/…), który zawiera artykuł.'
            );
        }

        $payload = $this->client->status($parsed->id);
        $status = $payload['status'] ?? null;
        if (!is_array($status)) {
            throw new FetchException('Brak treści posta w odpowiedzi.');
        }

        if (isset($status['article']) && is_array($status['article'])) {
            return $this->fromArticleStatus($status, $payload['author'] ?? null);
        }

        $threadPayload = $this->client->thread($parsed->id);
        $thread = $threadPayload['thread'] ?? null;
        $posts = is_array($thread) && $thread !== [] ? $thread : [$status];

        return $this->fromThread($posts, $threadPayload['author'] ?? $status['author'] ?? null, $status);
    }

    /**
     * @param array<string, mixed> $status
     * @param array<string, mixed>|null $authorRaw
     */
    private function fromArticleStatus(array $status, ?array $authorRaw): ArticleDocument
    {
        $article = $status['article'];
        if (!is_array($article)) {
            throw new FetchException('Artykuł jest pusty.');
        }

        $author = $this->authorFrom($authorRaw ?? ($status['author'] ?? []));
        $cover = $this->coverUrl($article['cover_media'] ?? null);
        $mediaRaw = $article['media_entities'] ?? [];
        if (!is_array($mediaRaw)) {
            $mediaRaw = [];
        }
        if (isset($article['cover_media']) && is_array($article['cover_media'])) {
            $mediaRaw[] = $article['cover_media'];
        }
        $blocks = $this->blocksFromDraft(
            is_array($article['content'] ?? null) ? $article['content'] : [],
            $this->indexMediaEntities($mediaRaw),
            (string) ($status['url'] ?? ''),
        );
        $title = trim((string) ($article['title'] ?? ''));
        if ($title === '') {
            $title = $this->fallbackTitle($status);
        }

        return new ArticleDocument(
            id: (string) ($status['id'] ?? ''),
            url: (string) ($status['url'] ?? ''),
            title: $title,
            author: $author,
            publishedAt: $this->formatDate($article['created_at'] ?? $status['created_at'] ?? null),
            coverUrl: $cover,
            blocks: $blocks,
            isLongArticle: true,
        );
    }

    /**
     * @param list<mixed> $posts
     * @param array<string, mixed>|null $authorRaw
     * @param array<string, mixed> $focal
     */
    private function fromThread(array $posts, ?array $authorRaw, array $focal): ArticleDocument
    {
        $author = $this->authorFrom($authorRaw ?? []);
        $blocks = [];
        $firstText = '';

        foreach ($posts as $post) {
            if (!is_array($post) || (($post['type'] ?? 'status') !== 'status')) {
                continue;
            }
            $text = trim((string) ($post['text'] ?? ''));
            if ($firstText === '' && $text !== '') {
                $firstText = $text;
            }
            if ($text !== '') {
                $blocks[] = ['type' => 'paragraph', 'html' => $this->plainToHtml($text)];
            }
            foreach ($this->photoUrls($post) as $url) {
                $blocks[] = ['type' => 'image', 'url' => $url];
            }
            $quote = $post['quote'] ?? null;
            if (is_array($quote) && ($quote['type'] ?? '') === 'status') {
                $blocks[] = [
                    'type' => 'tweet',
                    'name' => (string) (($quote['author']['name'] ?? '') ?: ''),
                    'handle' => (string) (($quote['author']['screen_name'] ?? '') ?: ''),
                    'text' => (string) ($quote['text'] ?? ''),
                    'url' => (string) ($quote['url'] ?? ''),
                    'photos' => $this->photoUrls($quote),
                ];
            }
        }

        $title = $this->titleFromText($firstText);

        return new ArticleDocument(
            id: (string) ($focal['id'] ?? ''),
            url: (string) ($focal['url'] ?? ''),
            title: $title,
            author: $author,
            publishedAt: $this->formatDate($focal['created_at'] ?? null),
            coverUrl: $this->photoUrls($focal)[0] ?? null,
            blocks: $blocks,
            isLongArticle: false,
        );
    }

    /**
     * @param array<string, mixed> $content
     * @param array<string, array<string, mixed>> $mediaById
     * @return list<array<string, mixed>>
     */
    private function blocksFromDraft(array $content, array $mediaById = [], string $pageUrl = ''): array
    {
        $rawBlocks = $content['blocks'] ?? [];
        if (!is_array($rawBlocks)) {
            return [];
        }
        $entityMap = EntityIndex::from($content['entityMap'] ?? $content['entities'] ?? []);

        $out = [];
        foreach ($rawBlocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? 'unstyled');
            $text = (string) ($block['text'] ?? '');

            if ($type === 'atomic') {
                $entity = $this->entityForBlock($block, $entityMap);
                foreach ($this->atomicBlocks($entity, $mediaById, $pageUrl) as $converted) {
                    $out[] = $converted;
                }
                continue;
            }

            $styles = $block['inlineStyleRanges'] ?? $block['inline_style_ranges'] ?? [];
            $ranges = $block['entityRanges'] ?? $block['entity_ranges'] ?? [];
            if ($type === 'code-block') {
                $out[] = ['type' => 'code', 'code' => $text];
                continue;
            }

            $html = $this->applyInline(
                $text,
                is_array($styles) ? $styles : [],
                is_array($ranges) ? $ranges : [],
                $entityMap,
            );
            $html = nl2br($html, false);

            $mapped = match ($type) {
                'header-one' => ['type' => 'heading', 'level' => 1, 'html' => $html],
                'header-two' => ['type' => 'heading', 'level' => 2, 'html' => $html],
                'header-three' => ['type' => 'heading', 'level' => 3, 'html' => $html],
                'blockquote' => ['type' => 'quote-line', 'html' => $html],
                'unordered-list-item' => ['type' => 'li', 'ordered' => false, 'html' => $html],
                'ordered-list-item' => ['type' => 'li', 'ordered' => true, 'html' => $html],
                default => $html === '' ? null : ['type' => 'paragraph', 'html' => $html],
            };
            if ($mapped !== null) {
                $out[] = $mapped;
            }
        }

        return $this->expandTweetEmbeds($out);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    private function expandTweetEmbeds(array $blocks): array
    {
        $out = [];
        $seen = [];
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') !== 'tweet-id') {
                $out[] = $block;
                continue;
            }
            $id = (string) ($block['id'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            try {
                $payload = $this->client->status($id);
                $status = $payload['status'] ?? [];
                if (!is_array($status)) {
                    continue;
                }
                $out[] = [
                    'type' => 'tweet',
                    'name' => (string) (($status['author']['name'] ?? '') ?: ''),
                    'handle' => (string) (($status['author']['screen_name'] ?? '') ?: ''),
                    'text' => (string) ($status['text'] ?? ''),
                    'url' => (string) ($status['url'] ?? ('https://x.com/i/status/' . $id)),
                    'photos' => $this->photoUrls($status),
                ];
            } catch (FetchException) {
                $out[] = [
                    'type' => 'tweet',
                    'name' => '',
                    'handle' => '',
                    'text' => 'Osadzony post ' . $id,
                    'url' => 'https://x.com/i/status/' . $id,
                    'photos' => [],
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>|null
     */
    private function entityForBlock(array $block, EntityIndex $entityMap): ?array
    {
        $ranges = $block['entityRanges'] ?? $block['entity_ranges'] ?? [];
        if (!is_array($ranges) || $ranges === []) {
            return null;
        }

        return $entityMap->get($ranges[0]['key'] ?? null);
    }

    /**
     * @param array<string, mixed>|null $entity
     * @param array<string, array<string, mixed>> $mediaById
     * @return list<array<string, mixed>>
     */
    private function atomicBlocks(?array $entity, array $mediaById, string $pageUrl = ''): array
    {
        if ($entity === null) {
            return [];
        }
        $type = strtoupper((string) ($entity['type'] ?? ''));
        $data = is_array($entity['data'] ?? null) ? $entity['data'] : [];

        if ($type === 'TWEET' || $type === 'POST') {
            $id = (string) ($data['tweetId'] ?? $data['tweet_id'] ?? $data['post_id'] ?? $data['postId'] ?? '');
            return $id !== '' ? [['type' => 'tweet-id', 'id' => $id]] : [];
        }

        if ($type === 'MARKDOWN') {
            $markdown = (string) ($data['markdown'] ?? $data['text'] ?? '');
            return $markdown !== '' ? $this->splitMarkdownParts($markdown) : [];
        }

        if ($type === 'DIVIDER' || $type === 'HR') {
            return [['type' => 'divider']];
        }

        if ($type === 'LATEX') {
            $src = (string) ($data['latex'] ?? $data['text'] ?? '');
            return $src !== '' ? [['type' => 'code', 'code' => $src]] : [];
        }

        if (in_array($type, ['MEDIA', 'IMAGE', 'PHOTO', 'VIDEO'], true)) {
            return $this->mediaBlocksFromEntity($data, $mediaById, $pageUrl);
        }

        $url = $this->mediaUrlFromData($data);
        $record = $this->lookupMedia($data, $mediaById);
        if (is_array($record) && ($record['kind'] ?? '') === 'video') {
            return [$this->videoBlock($record, (string) ($data['caption'] ?? ''), $pageUrl)];
        }
        $image = $url ?? (is_array($record) ? ($record['url'] ?? null) : null);
        return is_string($image) ? [['type' => 'image', 'url' => $image, 'caption' => (string) ($data['caption'] ?? '')]] : [];
    }

    /**
     * @param mixed $raw
     * @return array<string, array<string, mixed>>
     */
    private function indexMediaEntities(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $map = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $record = $this->describeMedia($item);
            if ($record === null) {
                continue;
            }
            foreach (['media_id', 'mediaId', 'id', 'media_key'] as $field) {
                $id = $this->asId($item[$field] ?? null);
                if ($id !== null) {
                    $map[$id] = $record;
                }
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    private function describeMedia(array $item): ?array
    {
        $info = is_array($item['media_info'] ?? null) ? $item['media_info'] : [];
        $typeName = (string) ($info['__typename'] ?? '');
        if ($typeName === 'ApiVideo' || $typeName === 'ApiGif') {
            $preview = $info['preview_image']['original_img_url'] ?? $info['preview_image']['url'] ?? null;
            $videoUrl = $this->bestMp4(is_array($info['variants'] ?? null) ? $info['variants'] : []);
            if (!is_string($preview) && $videoUrl === null) {
                return null;
            }

            return [
                'kind' => 'video',
                'url' => is_string($preview) ? $preview : null,
                'previewUrl' => is_string($preview) ? $preview : null,
                'videoUrl' => $videoUrl,
                'durationMs' => isset($info['duration_millis']) ? (int) $info['duration_millis'] : null,
            ];
        }

        $url = $this->coverUrl($item) ?? $this->mediaUrlFromData($item);
        if ($url === null) {
            return null;
        }

        return [
            'kind' => 'image',
            'url' => $url,
            'previewUrl' => $url,
            'videoUrl' => null,
            'durationMs' => null,
        ];
    }

    /**
     * @param list<mixed> $variants
     */
    private function bestMp4(array $variants): ?string
    {
        $best = null;
        $bestRate = -1;
        $fallback = null;
        $fallbackRate = -1;
        foreach ($variants as $variant) {
            if (!is_array($variant) || ($variant['content_type'] ?? '') !== 'video/mp4') {
                continue;
            }
            $url = $variant['url'] ?? null;
            if (!is_string($url) || $url === '') {
                continue;
            }
            $rate = (int) ($variant['bit_rate'] ?? $variant['bitrate'] ?? 0);
            if ($rate > $fallbackRate) {
                $fallback = $url;
                $fallbackRate = $rate;
            }
            if ($rate > 0 && $rate <= 2500000 && $rate > $bestRate) {
                $best = $url;
                $bestRate = $rate;
            }
        }

        return $best ?? $fallback;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, array<string, mixed>> $mediaById
     * @return list<array<string, mixed>>
     */
    private function mediaBlocksFromEntity(array $data, array $mediaById, string $pageUrl): array
    {
        $caption = trim((string) ($data['caption'] ?? $data['alt'] ?? $data['altText'] ?? $data['alt_text'] ?? ''));
        $records = [];
        $direct = $this->mediaUrlFromData($data);
        $items = $data['mediaItems'] ?? $data['media_items'] ?? null;
        if (is_array($items)) {
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $record = $this->lookupMedia($item, $mediaById);
                if ($record !== null) {
                    $records[] = $record;
                }
            }
        }
        $single = $this->lookupMedia($data, $mediaById);
        if ($single !== null) {
            $records[] = $single;
        }
        if ($records === [] && $direct !== null) {
            $records[] = ['kind' => 'image', 'url' => $direct, 'previewUrl' => $direct, 'videoUrl' => null, 'durationMs' => null];
        }

        $blocks = [];
        $seen = [];
        foreach ($records as $record) {
            $sig = ($record['kind'] ?? '') . '|' . ($record['videoUrl'] ?? $record['url'] ?? '');
            if (isset($seen[$sig])) {
                continue;
            }
            $seen[$sig] = true;
            if (($record['kind'] ?? '') === 'video') {
                $blocks[] = $this->videoBlock($record, $caption, $pageUrl);
            } elseif (isset($record['url']) && is_string($record['url'])) {
                $blocks[] = ['type' => 'image', 'url' => $record['url'], 'caption' => $caption];
            }
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function videoBlock(array $record, string $caption, string $pageUrl): array
    {
        return [
            'type' => 'video',
            'url' => $record['videoUrl'] ?? $pageUrl,
            'pageUrl' => $pageUrl,
            'previewUrl' => $record['previewUrl'] ?? $record['url'] ?? null,
            'caption' => $caption,
            'durationMs' => $record['durationMs'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, array<string, mixed>> $mediaById
     * @return array<string, mixed>|null
     */
    private function lookupMedia(array $data, array $mediaById): ?array
    {
        foreach (['mediaId', 'media_id', 'id', 'media_key'] as $field) {
            $id = $this->asId($data[$field] ?? null);
            if ($id !== null && isset($mediaById[$id])) {
                return $mediaById[$id];
            }
        }

        return null;
    }

    private function asId(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mediaUrlFromData(array $data): ?string
    {
        $candidates = [
            $data['url'] ?? null,
            $data['src'] ?? null,
            $data['media_url_https'] ?? null,
            $data['media_url'] ?? null,
        ];
        $info = $data['media_info'] ?? $data['mediaInfo'] ?? null;
        if (is_array($info)) {
            $candidates[] = $info['original_img_url'] ?? null;
            $candidates[] = $info['media_url_https'] ?? null;
        }
        foreach ($candidates as $url) {
            if (is_string($url) && str_starts_with($url, 'http')) {
                return $url;
            }
        }

        return null;
    }

    /**
     * @param list<mixed> $styles
     * @param list<mixed> $entities
     */
    private function applyInline(string $text, array $styles, array $entities, EntityIndex $entityMap): string
    {
        if ($text === '') {
            return '';
        }
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $n = count($chars);
        $open = array_fill(0, $n + 1, []);
        $close = array_fill(0, $n + 1, []);

        foreach ($styles as $range) {
            if (!is_array($range)) {
                continue;
            }
            $start = (int) ($range['offset'] ?? 0);
            $len = (int) ($range['length'] ?? 0);
            $end = min($n, $start + $len);
            $tag = match (strtolower((string) ($range['style'] ?? ''))) {
                'bold' => 'strong',
                'italic' => 'em',
                'underline' => 'u',
                'strikethrough', 'strike' => 's',
                default => null,
            };
            if ($tag === null || $start >= $end) {
                continue;
            }
            $open[$start][] = '<' . $tag . '>';
            array_unshift($close[$end], '</' . $tag . '>');
        }

        foreach ($entities as $range) {
            if (!is_array($range)) {
                continue;
            }
            $start = (int) ($range['offset'] ?? 0);
            $len = (int) ($range['length'] ?? 0);
            $end = min($n, $start + $len);
            $entity = $entityMap->get($range['key'] ?? null);
            if (!is_array($entity) || $start >= $end) {
                continue;
            }
            $etype = strtoupper((string) ($entity['type'] ?? ''));
            $data = is_array($entity['data'] ?? null) ? $entity['data'] : [];
            $href = null;
            if ($etype === 'LINK' || $etype === 'URL') {
                $href = (string) ($data['url'] ?? $data['href'] ?? '');
            } elseif ($etype === 'MENTION') {
                $screen = (string) ($data['screen_name'] ?? $data['screenName'] ?? '');
                if ($screen !== '') {
                    $href = 'https://x.com/' . rawurlencode($screen);
                }
            }
            if ($href !== null && $href !== '') {
                $safe = htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $open[$start][] = '<a href="' . $safe . '">';
                array_unshift($close[$end], '</a>');
            }
        }

        $html = '';
        for ($i = 0; $i < $n; $i++) {
            $html .= implode('', $open[$i]);
            $html .= htmlspecialchars($chars[$i], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= implode('', $close[$i + 1]);
        }

        return trim($html);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function splitMarkdownParts(string $markdown): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $markdown) ?: [];
        $parts = [];
        $buffer = [];
        $inFence = false;
        $fenceLang = '';
        $code = [];

        $flushMarkdown = static function () use (&$parts, &$buffer): void {
            $text = trim(implode("\n", $buffer));
            $buffer = [];
            if ($text !== '') {
                $parts[] = ['type' => 'markdown', 'markdown' => $text];
            }
        };

        $flushFence = static function () use (&$parts, &$code, &$fenceLang): void {
            $body = implode("\n", $code);
            $code = [];
            $lang = strtolower($fenceLang);
            $fenceLang = '';
            if (in_array($lang, ['markdown', 'md'], true)) {
                $parts[] = ['type' => 'md-card', 'markdown' => $body];
            } else {
                $parts[] = ['type' => 'code', 'code' => $body];
            }
        };

        foreach ($lines as $line) {
            if (!$inFence && preg_match('/^```\s*([\w+.#-]*)\s*$/', $line, $match)) {
                $flushMarkdown();
                $inFence = true;
                $fenceLang = $match[1] ?? '';
                $code = [];
                continue;
            }
            if ($inFence && preg_match('/^```\s*$/', $line)) {
                $flushFence();
                $inFence = false;
                continue;
            }
            if ($inFence) {
                $code[] = $line;
            } else {
                $buffer[] = $line;
            }
        }
        if ($inFence) {
            $flushFence();
        } else {
            $flushMarkdown();
        }

        return $parts !== [] ? $parts : [['type' => 'markdown', 'markdown' => $markdown]];
    }

    /**
     * @param mixed $cover
     */
    private function coverUrl(mixed $cover): ?string
    {
        if (!is_array($cover)) {
            return null;
        }
        $info = $cover['media_info'] ?? null;
        if (is_array($info) && is_string($info['original_img_url'] ?? null)) {
            return $info['original_img_url'];
        }

        return $this->mediaUrlFromData($cover);
    }

    /**
     * @param array<string, mixed> $status
     * @return list<string>
     */
    private function photoUrls(array $status): array
    {
        $photos = $status['media']['photos'] ?? $status['media']['all'] ?? [];
        if (!is_array($photos)) {
            return [];
        }
        $urls = [];
        foreach ($photos as $photo) {
            if (!is_array($photo)) {
                continue;
            }
            $type = (string) ($photo['type'] ?? 'photo');
            if (!in_array($type, ['photo', 'gif', 'image'], true)) {
                continue;
            }
            $url = $photo['url'] ?? null;
            if (is_string($url) && $url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function authorFrom(array $raw): Author
    {
        $handle = (string) ($raw['screen_name'] ?? 'unknown');

        return new Author(
            name: (string) ($raw['name'] ?? $handle),
            handle: $handle,
            avatarUrl: isset($raw['avatar_url']) && is_string($raw['avatar_url']) ? $raw['avatar_url'] : null,
            profileUrl: (string) ($raw['url'] ?? ('https://x.com/' . $handle)),
        );
    }

    /**
     * @param array<string, mixed> $status
     */
    private function fallbackTitle(array $status): string
    {
        $text = trim((string) ($status['text'] ?? ''));
        return $this->titleFromText($text !== '' ? $text : 'Post z X');
    }

    private function titleFromText(string $text): string
    {
        $line = trim(preg_replace('/\s+/u', ' ', explode("\n", $text)[0] ?? '') ?? '');
        if (mb_strlen($line) > 90) {
            return rtrim(mb_substr($line, 0, 87)) . '…';
        }

        return $line !== '' ? $line : 'Post z X';
    }

    private function formatDate(mixed $raw): ?string
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return $raw;
        }

        return date('Y-m-d H:i', $ts);
    }

    private function plainToHtml(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escaped = preg_replace(
            '/https?:\/\/[^\s<]+/u',
            '<a href="$0">$0</a>',
            $escaped,
        ) ?? $escaped;

        return nl2br($escaped, false);
    }
}
