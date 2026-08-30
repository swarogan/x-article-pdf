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
        public ?string $translatedTo = null,
        public ?string $translationModel = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'title' => $this->title,
            'author' => [
                'name' => $this->author->name,
                'handle' => $this->author->handle,
                'avatarUrl' => $this->author->avatarUrl,
                'profileUrl' => $this->author->profileUrl,
            ],
            'publishedAt' => $this->publishedAt,
            'coverUrl' => $this->coverUrl,
            'blocks' => $this->blocks,
            'isLongArticle' => $this->isLongArticle,
            'translatedTo' => $this->translatedTo,
            'translationModel' => $this->translationModel,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $authorRaw = $data['author'] ?? null;
        if (!is_array($authorRaw) || !is_string($data['id'] ?? null) || !is_string($data['url'] ?? null)
            || !is_string($data['title'] ?? null) || !is_array($data['blocks'] ?? null)) {
            return null;
        }
        $name = $authorRaw['name'] ?? null;
        $handle = $authorRaw['handle'] ?? null;
        if (!is_string($name) || !is_string($handle)) {
            return null;
        }
        $blocks = [];
        foreach ($data['blocks'] as $block) {
            if (is_array($block)) {
                $blocks[] = $block;
            }
        }

        return new self(
            id: $data['id'],
            url: $data['url'],
            title: $data['title'],
            author: new Author(
                $name,
                $handle,
                is_string($authorRaw['avatarUrl'] ?? null) ? $authorRaw['avatarUrl'] : null,
                is_string($authorRaw['profileUrl'] ?? null) ? $authorRaw['profileUrl'] : '',
            ),
            publishedAt: is_string($data['publishedAt'] ?? null) ? $data['publishedAt'] : null,
            coverUrl: is_string($data['coverUrl'] ?? null) ? $data['coverUrl'] : null,
            blocks: $blocks,
            isLongArticle: (bool) ($data['isLongArticle'] ?? false),
            translatedTo: is_string($data['translatedTo'] ?? null) ? $data['translatedTo'] : null,
            translationModel: is_string($data['translationModel'] ?? null) ? $data['translationModel'] : null,
        );
    }
}
