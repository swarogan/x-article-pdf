<?php

declare(strict_types=1);

namespace XArticlePdf;

final readonly class Author
{
    public function __construct(
        public string $name,
        public string $handle,
        public ?string $avatarUrl,
        public string $profileUrl,
    ) {
    }
}
