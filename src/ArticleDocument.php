<?php

declare(strict_types=1);

namespace XArticlePdf;

final readonly class ArticleDocument
{
    /**
     * @param list<array<string, mixed>> $blocks
     */
    public function __construct(
        public string $id,
        public string $url,
        public string $title,
        public Author $author,
        public ?string $publishedAt,
        public ?string $coverUrl,
        public array $blocks,
        public bool $isLongArticle,
    ) {
    }
}
