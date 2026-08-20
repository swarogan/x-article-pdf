<?php

declare(strict_types=1);

namespace XArticlePdf;

final class MarkdownExporter
{
    public function build(ArticleDocument $doc): string
    {
        $lines = [];
        $lines[] = '# ' . $doc->title;
        $lines[] = '';
        $by = trim($doc->author->name . ' (@' . $doc->author->handle . ')');
        $meta = $by;
        if ($doc->publishedAt !== null && $doc->publishedAt !== '') {
            $meta .= ' · ' . $doc->publishedAt;
        }
        $lines[] = $meta;
        $lines[] = '';
        if (is_string($doc->coverUrl) && $doc->coverUrl !== '') {
            $lines[] = '![](' . $doc->coverUrl . ')';
            $lines[] = '';
        }

        $listBuffer = [];
        $listOrdered = false;
        $flushList = static function () use (&$lines, &$listBuffer, &$listOrdered): void {
            if ($listBuffer === []) {
                return;
            }
            foreach ($listBuffer as $i => $item) {
                $prefix = $listOrdered ? ((string) ($i + 1) . '. ') : '- ';
                $lines[] = $prefix . $item;
            }
            $lines[] = '';
            $listBuffer = [];
        };

        foreach ($doc->blocks as $block) {
            $type = (string) ($block['type'] ?? '');
            if ($type === 'li') {
                $wantOrdered = !empty($block['ordered']);
                if ($listBuffer !== [] && $listOrdered !== $wantOrdered) {
                    $flushList();
                }
                $listOrdered = $wantOrdered;
                $listBuffer[] = $this->htmlToMarkdown((string) ($block['html'] ?? ''));
                continue;
            }
            $flushList();
            foreach ($this->blockLines($block) as $line) {
                $lines[] = $line;
            }
        }
        $flushList();

        $lines[] = '';
        $lines[] = 'Źródło: ' . $doc->url;
        $lines[] = '';

        return implode("\n", $lines);
    }

    public static function filename(ArticleDocument $doc): string
    {
        $base = $doc->author->handle . '-' . $doc->id;
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base) ?? 'x-article';

        return $base . '.md';
    }

    /**
     * @param array<string, mixed> $block
     * @return list<string>
     */
    private function blockLines(array $block): array
    {
        return match ((string) ($block['type'] ?? '')) {
            'paragraph' => $this->wrap($this->htmlToMarkdown((string) ($block['html'] ?? '')), ''),
            'heading' => $this->wrap($this->htmlToMarkdown((string) ($block['html'] ?? '')), str_repeat('#', max(1, min(3, (int) ($block['level'] ?? 2)))) . ' '),
            'quote-line' => $this->wrap($this->htmlToMarkdown((string) ($block['html'] ?? '')), '> '),
            'image' => $this->imageLines($block),
            'video' => $this->videoLines($block),
            'tweet' => $this->tweetLines($block),
            'markdown', 'md-card' => $this->wrap(trim((string) ($block['markdown'] ?? '')), ''),
            'code' => $this->codeLines((string) ($block['code'] ?? '')),
            'divider' => ['---', ''],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private function wrap(string $text, string $prefix): array
    {
        if ($text === '') {
            return [];
        }

        return [$prefix . $text, ''];
    }

    /**
     * @param array<string, mixed> $block
     * @return list<string>
     */
    private function imageLines(array $block): array
    {
        $url = (string) ($block['url'] ?? '');
        if ($url === '') {
            return [];
        }
        $caption = trim((string) ($block['caption'] ?? ''));

        return ['![' . $this->escapeAlt($caption) . '](' . $url . ')', ''];
    }

    /**
     * @param array<string, mixed> $block
     * @return list<string>
     */
    private function videoLines(array $block): array
    {
        $file = (string) ($block['url'] ?? '');
        $caption = trim((string) ($block['caption'] ?? ''));
        $preview = (string) ($block['previewUrl'] ?? '');
        if ($file === '' && $preview === '') {
            return [];
        }
        $out = [];
        if ($file !== '') {
            $poster = $preview !== '' ? ' poster="' . $this->escapeAttr($preview) . '"' : '';
            $out[] = '<video src="' . $this->escapeAttr($file) . '"' . $poster . ' controls></video>';
            $out[] = $file;
        } elseif ($preview !== '') {
            $out[] = '![' . $this->escapeAlt($caption) . '](' . $preview . ')';
        }
        if ($caption !== '') {
            $out[] = '*' . $caption . '*';
        }
        $out[] = '';

        return $out;
    }

    /**
     * @param array<string, mixed> $block
     * @return list<string>
     */
    private function tweetLines(array $block): array
    {
        $who = trim((string) ($block['name'] ?? '') . ' @' . (string) ($block['handle'] ?? ''));
        $text = trim((string) ($block['text'] ?? ''));
        $url = (string) ($block['url'] ?? '');
        $body = $who;
        if ($text !== '') {
            $body .= ($body !== '' ? ': ' : '') . $text;
        }
        $lines = [];
        if ($body !== '') {
            $lines[] = '> ' . str_replace("\n", "\n> ", $body);
        }
        if ($url !== '') {
            $lines[] = '>';
            $lines[] = '> ' . $url;
        }
        foreach ($block['photos'] ?? [] as $photo) {
            if (is_string($photo) && $photo !== '') {
                $lines[] = '>';
                $lines[] = '> ![](' . $photo . ')';
            }
        }
        if ($lines === []) {
            return [];
        }
        $lines[] = '';

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function codeLines(string $code): array
    {
        $code = trim($code, "\n");
        if ($code === '') {
            return [];
        }

        return ['```', $code, '```', ''];
    }

    private function htmlToMarkdown(string $html): string
    {
        $html = str_replace(['<br />', '<br/>', '<br>'], "\n", $html);
        $html = preg_replace('#</?(strong|b)>#i', '**', $html) ?? $html;
        $html = preg_replace('#</?(em|i)>#i', '*', $html) ?? $html;
        $html = preg_replace('#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', '[$2]($1)', $html) ?? $html;
        $html = strip_tags($html);

        return trim(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function escapeAlt(string $text): string
    {
        return str_replace(['[', ']', "\n"], ['\\[', '\\]', ' '], $text);
    }

    private function escapeAttr(string $url): string
    {
        return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
