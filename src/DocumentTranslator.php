<?php

declare(strict_types=1);

namespace XArticlePdf;

final class DocumentTranslator
{
    private const SKIP_TYPES = ['code', 'divider'];

    public function __construct(private readonly Translator $translator)
    {
    }

    public function translate(ArticleDocument $doc, string $targetLanguage, ?callable $onProgress = null): ArticleDocument
    {
        $jobs = [];
        if ($this->shouldTranslate($doc->title)) {
            $jobs[] = ['kind' => 'title'];
        }
        foreach ($doc->blocks as $index => $block) {
            $type = (string) ($block['type'] ?? '');
            if (in_array($type, self::SKIP_TYPES, true)) {
                continue;
            }
            foreach (['html', 'markdown', 'caption', 'text'] as $field) {
                if (!isset($block[$field]) || !is_string($block[$field]) || !$this->shouldTranslate($block[$field])) {
                    continue;
                }
                $jobs[] = ['kind' => 'block', 'index' => $index, 'field' => $field];
            }
        }
        if ($jobs === []) {
            return $doc;
        }

        $texts = [];
        foreach ($jobs as $job) {
            $texts[] = $job['kind'] === 'title'
                ? $doc->title
                : (string) $doc->blocks[$job['index']][$job['field']];
        }
        $translated = $this->translator->translate($texts, $targetLanguage, $onProgress);
        if (count($translated) !== count($jobs)) {
            throw new FetchException('Tłumaczenie zwróciło inną liczbę fragmentów.');
        }

        $title = $doc->title;
        $blocks = $doc->blocks;
        foreach ($jobs as $i => $job) {
            $value = $translated[$i];
            if ($job['kind'] === 'title') {
                $title = $value;
                continue;
            }
            $blocks[$job['index']][$job['field']] = $value;
        }

        return new ArticleDocument(
            id: $doc->id,
            url: $doc->url,
            title: $title,
            author: $doc->author,
            publishedAt: $doc->publishedAt,
            coverUrl: $doc->coverUrl,
            blocks: $blocks,
            isLongArticle: $doc->isLongArticle,
        );
    }

    private function shouldTranslate(string $text): bool
    {
        $plain = trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($plain === '') {
            return false;
        }

        return (bool) preg_match('/\p{L}{3,}/u', $plain);
    }
}
