<?php

declare(strict_types=1);

namespace XArticlePdf;

final class HtmlRenderer
{
    public function __construct(private readonly MediaStore $media)
    {
    }

    public function render(ArticleDocument $doc): string
    {
        $avatar = $doc->author->avatarUrl ? $this->img($doc->author->avatarUrl, 48, 48, 'avatar') : '';
        $cover = $doc->coverUrl && $doc->isLongArticle ? $this->img($doc->coverUrl, 720, 0, 'cover') : '';
        $body = $this->renderBlocks($doc->blocks);

        $title = $this->e($doc->title);
        $name = $this->e($doc->author->name);
        $handle = $this->e('@' . $doc->author->handle);
        $date = $this->e($doc->publishedAt ?? '');
        $url = $this->e($doc->url);
        $kind = $doc->isLongArticle ? 'Artykuł X' : 'Wątek X';

        return <<<HTML
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 11pt; color: #0f172a; line-height: 1.45; }
  h1 { font-size: 20pt; line-height: 1.25; margin: 12px 0 10px; }
  h2 { font-size: 14pt; margin: 16px 0 8px; }
  h3 { font-size: 12pt; margin: 14px 0 6px; }
  p { margin: 0 0 9px; }
  a { color: #0369a1; text-decoration: none; }
  .meta { color: #64748b; font-size: 9pt; }
  .avatar { width: 36px; height: 36px; border-radius: 18px; }
  .cover { width: 100%; margin: 8px 0 14px; }
  .photo { width: 100%; margin: 8px 0 12px; }
  .tweet { border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px 10px; margin: 10px 0; page-break-inside: avoid; }
  .tweet .who { font-size: 9pt; color: #475569; margin-bottom: 4px; }
  .quote { border-left: 3px solid #94a3b8; padding: 0 0 0 10px; color: #334155; margin: 8px 0; }
  .kind { font-size: 8pt; letter-spacing: 0.08em; text-transform: uppercase; color: #64748b; }
  ul, ol { margin: 0 0 10px 18px; }
  pre, code, .codeblock { font-family: dejavusansmono, monospace; font-size: 8pt; }
  .codeblock { background: #f1f5f9; border: 1px solid #cbd5e1; padding: 8px; margin: 8px 0 12px; }
  .codeblock pre { margin: 0; white-space: pre-wrap; }
  hr.div { border: 0; border-top: 1px solid #cbd5e1; margin: 14px 0; }
  table.md { border-collapse: collapse; width: 100%; margin: 8px 0 12px; }
  table.md th, table.md td { border: 1px solid #cbd5e1; padding: 4px 6px; font-size: 9pt; }
  .md h1 { font-size: 16pt; }
  .md h2 { font-size: 13pt; }
  .md h3 { font-size: 12pt; }
  .md ul, .md ol { margin: 0 0 10px 18px; }
  .caption { font-size: 9pt; color: #64748b; font-style: italic; margin: 2px 0 12px; text-align: center; }
</style>
</head>
<body>
  <div class="kind">{$kind}</div>
  <table width="100%" cellspacing="0" cellpadding="0">
    <tr>
      <td width="44" valign="middle">{$avatar}</td>
      <td valign="middle">
        <div><strong>{$name}</strong> <span class="meta">{$handle}</span></div>
        <div class="meta">{$date}</div>
      </td>
    </tr>
  </table>
  <h1>{$title}</h1>
  {$cover}
  {$body}
  <p class="meta">Źródło: <a href="{$url}">{$url}</a></p>
</body>
</html>
HTML;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function renderBlocks(array $blocks): string
    {
        $html = '';
        $listType = null;
        foreach ($blocks as $block) {
            $type = (string) ($block['type'] ?? '');
            if ($type === 'li') {
                $want = !empty($block['ordered']) ? 'ol' : 'ul';
                if ($listType !== $want) {
                    if ($listType !== null) {
                        $html .= '</' . $listType . '>';
                    }
                    $html .= '<' . $want . '>';
                    $listType = $want;
                }
                $html .= '<li>' . ($block['html'] ?? '') . '</li>';
                continue;
            }
            if ($listType !== null) {
                $html .= '</' . $listType . '>';
                $listType = null;
            }
            $html .= $this->renderBlock($block);
        }
        if ($listType !== null) {
            $html .= '</' . $listType . '>';
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function renderBlock(array $block): string
    {
        return match ((string) ($block['type'] ?? '')) {
            'paragraph' => '<p>' . ($block['html'] ?? '') . '</p>',
            'heading' => $this->heading($block),
            'quote-line' => '<div class="quote">' . ($block['html'] ?? '') . '</div>',
            'image' => $this->figure((string) ($block['url'] ?? ''), (string) ($block['caption'] ?? ''), 480),
            'video' => $this->video($block),
            'tweet' => $this->tweet($block),
            'markdown' => $this->markdown((string) ($block['markdown'] ?? '')),
            'md-card' => $this->markdownCard((string) ($block['markdown'] ?? '')),
            'code' => $this->codeBlock((string) ($block['code'] ?? '')),
            'divider' => '<hr class="div" />',
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $block
     */
    private function heading(array $block): string
    {
        $level = (int) ($block['level'] ?? 2);
        $level = max(1, min(3, $level));

        return '<h' . $level . '>' . ($block['html'] ?? '') . '</h' . $level . '>';
    }

    /**
     * @param array<string, mixed> $block
     */
    private function tweet(array $block): string
    {
        $name = $this->e((string) ($block['name'] ?? ''));
        $handle = $this->e((string) ($block['handle'] ?? ''));
        $text = nl2br($this->e((string) ($block['text'] ?? '')), false);
        $url = $this->e((string) ($block['url'] ?? ''));
        $who = trim($name . ' ' . ($handle !== '' ? '@' . $handle : ''));
        $photos = '';
        foreach ($block['photos'] ?? [] as $photo) {
            if (is_string($photo)) {
                $photos .= $this->img($photo, 640, 0, 'photo');
            }
        }

        return '<div class="tweet"><div class="who">' . $who . '</div><div>' . $text . '</div>' . $photos
            . ($url !== '' ? '<div class="meta"><a href="' . $url . '">' . $url . '</a></div>' : '')
            . '</div>';
    }

    private function markdownCard(string $source): string
    {
        $source = trim($source);
        if ($source === '') {
            return '';
        }
        $rich = (bool) preg_match('/^(#{1,6} |\s*[-*] |\s*\d+\. )|\|/m', $source);
        if ($rich) {
            $safe = preg_replace('/\[([^\]\n]{1,80})\](?!\()/', '`$1`', $source) ?? $source;
            $inner = $this->markdown($safe);
        } else {
            $inner = '<p>' . nl2br($this->e($source), false) . '</p>';
        }

        return '<table width="100%" bgcolor="#F1F5F9" cellpadding="10" cellspacing="0" style="margin:8px 0 12px;">'
            . '<tr><td style="border-left: 3px solid #0EA5E9;">' . $inner . '</td></tr></table>';
    }

    private function markdown(string $source): string
    {
        $source = trim($source);
        if ($source === '') {
            return '';
        }
        $converter = new \League\CommonMark\GithubFlavoredMarkdownConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
        $html = (string) $converter->convert($source);
        $html = preg_replace_callback(
            '/<img([^>]*?)src="([^"]+)"([^>]*)>/i',
            function (array $m): string {
                $path = $this->media->localPath(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($path === null) {
                    return '';
                }

                return '<img class="photo" src="' . $this->e($path) . '" width="720" />';
            },
            $html,
        ) ?? $html;
        $html = str_replace('<table>', '<table class="md">', $html);

        return '<div class="md">' . $html . '</div>';
    }

    private function codeBlock(string $code): string
    {
        $escaped = htmlspecialchars(trim($code, "\n"), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escaped = str_replace(["\r\n", "\n", "\r"], '<br />', $escaped);
        $escaped = str_replace('  ', '&nbsp;&nbsp;', $escaped);

        return '<table class="codeblock" width="100%" bgcolor="#F1F5F9" cellspacing="0" cellpadding="8">'
            . '<tr><td style="font-family: dejavusansmono, monospace; font-size: 8pt;">'
            . $escaped . '</td></tr></table>';
    }

    /**
     * @param array<string, mixed> $block
     */
    private function video(array $block): string
    {
        $preview = is_string($block['previewUrl'] ?? null) ? $this->img((string) $block['previewUrl'], 480, 0, 'photo') : '';
        $caption = $this->captionHtml((string) ($block['caption'] ?? ''));
        $file = (string) ($block['url'] ?? '');
        $duration = '';
        if (isset($block['durationMs']) && is_numeric($block['durationMs'])) {
            $seconds = (int) round(((int) $block['durationMs']) / 1000);
            $duration = sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
        }
        $linkHtml = '';
        if ($file !== '') {
            $safe = $this->e($file);
            $label = 'Video' . ($duration !== '' ? ' (' . $duration . ')' : '');
            $linkHtml = '<p class="meta">' . $label . ':<br /><span style="font-family:dejavusansmono,monospace;font-size:7pt;word-break:break-all;">'
                . '<a href="' . $safe . '">' . $safe . '</a></span></p>';
        }

        return $preview . $caption . $linkHtml;
    }

    private function figure(string $url, string $caption, int $width): string
    {
        return $this->img($url, $width, 0, 'photo') . $this->captionHtml($caption);
    }

    private function captionHtml(string $caption): string
    {
        $caption = trim($caption);
        if ($caption === '') {
            return '';
        }

        return '<p class="caption">' . $this->e($caption) . '</p>';
    }

    private function img(string $url, int $width, int $height, string $class): string
    {
        $path = $this->media->localPath($url);
        if ($path === null) {
            return '';
        }
        $src = $this->e($path);
        $size = $width > 0 ? ' width="' . $width . '"' : '';
        if ($height > 0) {
            $size .= ' height="' . $height . '"';
        }

        return '<img class="' . $class . '" src="' . $src . '"' . $size . ' />';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
