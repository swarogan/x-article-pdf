<?php

declare(strict_types=1);

namespace XArticlePdf;

final readonly class ParsedXUrl
{
    public function __construct(
        public string $kind,
        public string $id,
        public ?string $handle = null,
    ) {
    }
}
